<?php

declare(strict_types=1);

return [
	'routes' => [
		[
			'name' => 'Settings#index',
			'url' => '/settings/admin',
			'verb' => 'GET',
		],
		[
			'name' => 'Settings#save',
			'url' => '/settings/admin/save',
			'verb' => 'POST',
		],
		[
			'name' => 'Settings#testConnection',
			'url' => '/settings/admin/test-connection',
			'verb' => 'POST',
		],
	],
];
