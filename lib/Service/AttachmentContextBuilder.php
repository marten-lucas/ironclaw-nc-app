<?php

declare(strict_types=1);

namespace OCA\IronclawTalkBridge\Service;

use OCP\Files\IRootFolder;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class AttachmentContextBuilder {
	private ?bool $tesseractAvailable = null;

	public function __construct(
		private AppConfig $config,
		private IRootFolder $rootFolder,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<int,array<string,mixed>> $attachments
	 * @return array{attachments:array<int,array<string,mixed>>,errors:array<int,array<string,mixed>>}
	 */
	public function enrichForActor(array $attachments, string $actorId): array {
		$maxFileBytes = $this->config->getAttachmentMaxFileSizeBytes();
		$maxTotalBytes = $this->config->getAttachmentMaxTotalSizeBytes();
		$maxChars = $this->config->getAttachmentMaxExtractChars();
		$allowedMimePatterns = $this->config->getAttachmentAllowedMimePatterns();

		$enriched = [];
		$errors = [];
		$totalBytes = 0;

		foreach ($attachments as $attachment) {
			if (!is_array($attachment)) {
				continue;
			}

			$item = $this->normalizeAttachment($attachment);
			$mimeType = strtolower(trim((string)($item['mimeType'] ?? '')));
			if ($mimeType !== '' && !$this->isMimeAllowed($mimeType, $allowedMimePatterns)) {
				$errors[] = $this->errorEntry($item, 'mime_not_allowed', 'Der Dateityp ist nicht erlaubt.');
				continue;
			}

			$download = $this->downloadAttachmentBytes($item, $actorId, $maxFileBytes);
			if (!$download['ok']) {
				$errors[] = $this->errorEntry($item, (string)$download['errorCode'], (string)$download['message']);
				continue;
			}

			$sizeBytes = (int)($download['sizeBytes'] ?? 0);
			if ($totalBytes + $sizeBytes > $maxTotalBytes) {
				$errors[] = $this->errorEntry($item, 'total_size_limit_exceeded', 'Gesamtlimit fuer Anhangsdaten erreicht.');
				continue;
			}
			$totalBytes += $sizeBytes;

			$textExtract = $this->extractText((string)$download['bytes'], $mimeType, $item, $maxChars);
			$item['sizeBytes'] = $sizeBytes;
			$item['downloadSource'] = $download['source'];
			$item['extract'] = $textExtract;

			if (($textExtract['status'] ?? '') === 'error') {
				$errors[] = $this->errorEntry(
					$item,
					(string)($textExtract['errorCode'] ?? 'extract_failed'),
					(string)($textExtract['message'] ?? 'Anhang konnte nicht verarbeitet werden.')
				);
			}

			$enriched[] = $item;
		}

		return [
			'attachments' => $enriched,
			'errors' => $errors,
		];
	}

	/**
	 * @param array<string,mixed> $attachment
	 * @return array<string,mixed>
	 */
	private function normalizeAttachment(array $attachment): array {
		$normalized = $attachment;
		$normalized['name'] = trim((string)($attachment['name'] ?? ''));
		$normalized['id'] = trim((string)($attachment['id'] ?? $attachment['fileId'] ?? ''));
		$normalized['fileId'] = trim((string)($attachment['fileId'] ?? $attachment['id'] ?? ''));
		$normalized['mimeType'] = trim((string)($attachment['mimeType'] ?? ''));
		$normalized['path'] = trim((string)($attachment['path'] ?? ''));
		$normalized['link'] = trim((string)($attachment['link'] ?? ''));
		$normalized['sourceType'] = trim((string)($attachment['sourceType'] ?? 'parameter'));
		return $normalized;
	}

	/**
	 * @param array<string,mixed> $attachment
	 * @return array{ok:bool,bytes?:string,sizeBytes?:int,source?:string,errorCode?:string,message?:string}
	 */
	private function downloadAttachmentBytes(array $attachment, string $actorId, int $maxFileBytes): array {
		$fileId = trim((string)($attachment['fileId'] ?? ''));
		$path = trim((string)($attachment['path'] ?? ''));
		$link = trim((string)($attachment['link'] ?? ''));

		if ($actorId !== '' && $fileId !== '') {
			$result = $this->downloadFromUserFolder($actorId, $fileId, '', $maxFileBytes);
			if ($result['ok']) {
				return $result;
			}
		}

		if ($actorId !== '' && $path !== '') {
			$result = $this->downloadFromUserFolder($actorId, '', $path, $maxFileBytes);
			if ($result['ok']) {
				return $result;
			}
		}

		if ($link !== '' && $this->isHttpUrl($link)) {
			return $this->downloadFromHttp($link, $maxFileBytes);
		}

		return [
			'ok' => false,
			'errorCode' => 'attachment_not_resolvable',
			'message' => 'Anhang konnte serverseitig nicht aufgeloest werden.',
		];
	}

	/**
	 * @return array{ok:bool,bytes?:string,sizeBytes?:int,source?:string,errorCode?:string,message?:string}
	 */
	private function downloadFromUserFolder(string $actorId, string $fileId, string $path, int $maxFileBytes): array {
		try {
			$userFolder = $this->rootFolder->getUserFolder($actorId);
			$node = null;
			if ($fileId !== '' && method_exists($userFolder, 'getById')) {
				$nodes = $userFolder->getById((int)$fileId);
				if (is_array($nodes) && isset($nodes[0])) {
					$node = $nodes[0];
				}
			}

			if ($node === null && $path !== '' && method_exists($userFolder, 'get')) {
				$node = $userFolder->get($path);
			}

			if ($node === null) {
				return [
					'ok' => false,
					'errorCode' => 'attachment_not_found',
					'message' => 'Anhang wurde im Benutzerordner nicht gefunden.',
				];
			}

			if (method_exists($node, 'isReadable') && !$node->isReadable()) {
				return [
					'ok' => false,
					'errorCode' => 'attachment_not_readable',
					'message' => 'Anhang ist nicht lesbar.',
				];
			}

			$size = method_exists($node, 'getSize') ? (int)$node->getSize() : 0;
			if ($size > 0 && $size > $maxFileBytes) {
				return [
					'ok' => false,
					'errorCode' => 'attachment_too_large',
					'message' => 'Anhang ueberschreitet das Dateilimit.',
				];
			}

			$bytes = '';
			if (method_exists($node, 'fopen')) {
				$bytes = $this->readStreamWithLimit($node->fopen('r'), $maxFileBytes);
			} elseif (method_exists($node, 'getContent')) {
				$bytes = (string)$node->getContent();
			}

			if ($bytes === '') {
				return [
					'ok' => false,
					'errorCode' => 'attachment_empty',
					'message' => 'Anhang enthaelt keine Daten oder konnte nicht gelesen werden.',
				];
			}

			if (strlen($bytes) > $maxFileBytes) {
				return [
					'ok' => false,
					'errorCode' => 'attachment_too_large',
					'message' => 'Anhang ueberschreitet das Dateilimit.',
				];
			}

			return [
				'ok' => true,
				'bytes' => $bytes,
				'sizeBytes' => strlen($bytes),
				'source' => $fileId !== '' ? 'nextcloud_user_file_id' : 'nextcloud_user_path',
			];
		} catch (\Throwable $e) {
			$this->logger->warning('Attachment download from user folder failed', [
				'app' => 'ironclaw_talk_bridge',
				'actorId' => $actorId,
				'fileId' => $fileId,
				'path' => $path,
				'error' => $e->getMessage(),
			]);
			return [
				'ok' => false,
				'errorCode' => 'attachment_download_failed',
				'message' => 'Anhang konnte serverseitig nicht geladen werden.',
			];
		}
	}

	/**
	 * @return array{ok:bool,bytes?:string,sizeBytes?:int,source?:string,errorCode?:string,message?:string}
	 */
	private function downloadFromHttp(string $url, int $maxFileBytes): array {
		try {
			$client = $this->clientService->newClient();
			$response = $client->get($url, [
				'timeout' => 10,
				'connect_timeout' => 5,
				'http_errors' => false,
				'nextcloud' => [
					'allow_local_address' => true,
				],
			]);

			$status = $response->getStatusCode();
			if ($status < 200 || $status >= 300) {
				return [
					'ok' => false,
					'errorCode' => 'attachment_http_error',
					'message' => 'Anhang-Download schlug fehl (HTTP ' . $status . ').',
				];
			}

			$body = (string)$response->getBody();
			if ($body === '') {
				return [
					'ok' => false,
					'errorCode' => 'attachment_empty',
					'message' => 'Anhang enthaelt keine Daten.',
				];
			}
			if (strlen($body) > $maxFileBytes) {
				return [
					'ok' => false,
					'errorCode' => 'attachment_too_large',
					'message' => 'Anhang ueberschreitet das Dateilimit.',
				];
			}

			return [
				'ok' => true,
				'bytes' => $body,
				'sizeBytes' => strlen($body),
				'source' => 'http_link',
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'errorCode' => 'attachment_download_failed',
				'message' => 'Anhang konnte per HTTP nicht geladen werden.',
			];
		}
	}

	/**
	 * @param array<string,mixed> $attachment
	 * @return array<string,mixed>
	 */
	private function extractText(string $bytes, string $mimeType, array $attachment, int $maxChars): array {
		$mime = strtolower(trim($mimeType));
		if ($mime === '' && isset($attachment['name'])) {
			$mime = $this->guessMimeFromFilename((string)$attachment['name']);
		}

		if ($this->isTextLikeMime($mime)) {
			$text = $this->truncateText($this->toUtf8($bytes), $maxChars);
			return [
				'status' => 'ok',
				'method' => 'text_direct',
				'text' => $text,
				'truncated' => mb_strlen($text) >= $maxChars,
			];
		}

		if ($mime === 'application/pdf') {
			$text = $this->truncateText($this->extractPdfText($bytes), $maxChars);
			if ($text === '') {
				return [
					'status' => 'error',
					'errorCode' => 'pdf_extract_failed',
					'method' => 'pdf_heuristic',
					'message' => 'PDF konnte nicht in Text umgewandelt werden.',
				];
			}
			return [
				'status' => 'ok',
				'method' => 'pdf_heuristic',
				'text' => $text,
				'truncated' => mb_strlen($text) >= $maxChars,
			];
		}

		if (str_starts_with($mime, 'image/')) {
			if (!$this->config->isAttachmentOcrEnabled()) {
				return [
					'status' => 'error',
					'errorCode' => 'ocr_disabled',
					'message' => 'OCR ist deaktiviert.',
				];
			}
			if (!$this->isTesseractAvailable()) {
				return [
					'status' => 'error',
					'errorCode' => 'ocr_unavailable',
					'message' => 'OCR ist nicht verfuegbar (tesseract fehlt).',
				];
			}

			$text = $this->truncateText($this->runOcr($bytes, $mime), $maxChars);
			if ($text === '') {
				return [
					'status' => 'error',
					'errorCode' => 'ocr_failed',
					'message' => 'OCR lieferte keinen Text.',
				];
			}
			return [
				'status' => 'ok',
				'method' => 'ocr_tesseract',
				'text' => $text,
				'truncated' => mb_strlen($text) >= $maxChars,
			];
		}

		return [
			'status' => 'error',
			'errorCode' => 'mime_not_extractable',
			'message' => 'Dateityp wird fuer Extraktion nicht unterstuetzt.',
		];
	}

	private function isTextLikeMime(string $mimeType): bool {
		return str_starts_with($mimeType, 'text/')
			|| in_array($mimeType, ['application/json', 'application/xml', 'text/markdown', 'text/csv'], true);
	}

	private function guessMimeFromFilename(string $name): string {
		$lower = strtolower($name);
		if (str_ends_with($lower, '.pdf')) {
			return 'application/pdf';
		}
		if (str_ends_with($lower, '.txt') || str_ends_with($lower, '.md') || str_ends_with($lower, '.csv')) {
			return 'text/plain';
		}
		if (str_ends_with($lower, '.png')) {
			return 'image/png';
		}
		if (str_ends_with($lower, '.jpg') || str_ends_with($lower, '.jpeg')) {
			return 'image/jpeg';
		}
		return '';
	}

	private function isMimeAllowed(string $mimeType, array $patterns): bool {
		if ($mimeType === '') {
			return true;
		}
		if ($patterns === []) {
			return true;
		}

		foreach ($patterns as $pattern) {
			$pattern = strtolower(trim((string)$pattern));
			if ($pattern === '') {
				continue;
			}
			if (str_ends_with($pattern, '/*')) {
				$prefix = substr($pattern, 0, -1);
				if ($prefix !== '' && str_starts_with($mimeType, $prefix)) {
					return true;
				}
				continue;
			}
			if ($mimeType === $pattern) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param resource $stream
	 */
	private function readStreamWithLimit($stream, int $maxBytes): string {
		if (!is_resource($stream)) {
			return '';
		}

		$buffer = '';
		while (!feof($stream) && strlen($buffer) <= $maxBytes) {
			$chunk = fread($stream, 8192);
			if ($chunk === false || $chunk === '') {
				break;
			}
			$buffer .= $chunk;
		}
		fclose($stream);
		return $buffer;
	}

	private function toUtf8(string $bytes): string {
		$encoding = mb_detect_encoding($bytes, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
		if ($encoding === false) {
			$encoding = 'UTF-8';
		}
		return (string)mb_convert_encoding($bytes, 'UTF-8', $encoding);
	}

	private function truncateText(string $text, int $maxChars): string {
		$clean = trim((string)preg_replace('/\s+/u', ' ', $text));
		if (mb_strlen($clean) <= $maxChars) {
			return $clean;
		}
		return rtrim(mb_substr($clean, 0, $maxChars)) . ' ...';
	}

	private function extractPdfText(string $pdfBytes): string {
		$chunks = [];
		$matches = [];
		preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdfBytes, $matches);
		if (!isset($matches[1]) || !is_array($matches[1]) || $matches[1] === []) {
			return '';
		}

		foreach (($matches[1] ?? []) as $streamChunk) {
			if (!is_string($streamChunk) || $streamChunk === '') {
				continue;
			}
			$decoded = @gzuncompress($streamChunk);
			if (!is_string($decoded) || $decoded === '') {
				$decoded = @gzdecode($streamChunk);
			}
			if (!is_string($decoded) || $decoded === '') {
				$decoded = $streamChunk;
			}
			if (preg_match_all('/\(([^\)]{1,500})\)\s*T[Jj]/', $decoded, $textMatches) === 1) {
				foreach (($textMatches[1] ?? []) as $token) {
					$chunks[] = str_replace(['\\n', '\\r', '\\t', '\\\\', '\\(', '\\)'], ["\n", "\r", "\t", "\\", '(', ')'], (string)$token);
				}
			}
		}

		return $this->toUtf8(implode("\n", $chunks));
	}

	private function isTesseractAvailable(): bool {
		if ($this->tesseractAvailable !== null) {
			return $this->tesseractAvailable;
		}

		$binary = trim((string)@shell_exec('command -v tesseract 2>/dev/null'));
		$this->tesseractAvailable = $binary !== '';
		return $this->tesseractAvailable;
	}

	private function runOcr(string $bytes, string $mimeType): string {
		$tempPath = tempnam(sys_get_temp_dir(), 'ictb_ocr_');
		if ($tempPath === false) {
			return '';
		}

		$ext = str_contains($mimeType, 'png') ? '.png' : '.jpg';
		$imagePath = $tempPath . $ext;
		if (!@rename($tempPath, $imagePath)) {
			@unlink($tempPath);
			return '';
		}
		if (@file_put_contents($imagePath, $bytes) === false) {
			@unlink($imagePath);
			return '';
		}

		$languages = $this->config->getAttachmentOcrLanguages();
		$command = sprintf('tesseract %s stdout -l %s 2>/dev/null', escapeshellarg($imagePath), escapeshellarg($languages));
		$output = (string)@shell_exec($command);
		@unlink($imagePath);

		return $this->toUtf8($output);
	}

	/**
	 * @param array<string,mixed> $attachment
	 * @return array<string,mixed>
	 */
	private function errorEntry(array $attachment, string $code, string $message): array {
		return [
			'attachmentName' => (string)($attachment['name'] ?? ''),
			'attachmentId' => (string)($attachment['id'] ?? ''),
			'code' => $code,
			'message' => $message,
		];
	}

	private function isHttpUrl(string $url): bool {
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return false;
		}
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		return $scheme === 'http' || $scheme === 'https';
	}
}
