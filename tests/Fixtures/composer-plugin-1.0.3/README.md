# Legacy integration fixture

Exact source and Composer manifest from Componenta Composer Plugin commit [`e15044d213fe90295379ca3d938eb2dfc496bdf1`](https://github.com/componenta/composer-plugin/commit/e15044d213fe90295379ca3d938eb2dfc496bdf1) (release v1.0.3). Used exclusively by the offline Composer upgrade test; never autoload these files into the Pest process. The test copies the fixture to an isolated path repository and sets the explicit version in its temporary composer.json.
