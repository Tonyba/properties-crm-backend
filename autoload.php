<?php

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'constants.php';

require_once PLUGIN_PATH . 'interfaces/interfaces.php';

require_once PLUGIN_PATH . 'Inc/Init.php';
require_once PLUGIN_PATH . 'Inc/Api/PropertiesApi.php';
require_once PLUGIN_PATH . 'Inc/Api/LeadsApi.php';
require_once PLUGIN_PATH . 'Inc/Api/TasksApi.php';
require_once PLUGIN_PATH . 'Inc/Api/EventsApi.php';
require_once PLUGIN_PATH . 'Inc/Api/DocumentsApi.php';

require_once PLUGIN_PATH . 'Inc/Service/UpdatesService.php';
require_once PLUGIN_PATH . 'Inc/Service/HelpersService.php';


require_once PLUGIN_PATH . 'Inc/Base/Activate.php';
require_once PLUGIN_PATH . 'Inc/Base/Deactivate.php';
require_once PLUGIN_PATH . 'Inc/Base/Enqueue.php';
require_once PLUGIN_PATH . 'Inc/Base/SettingsLinks.php';

require_once PLUGIN_PATH . 'Inc/Pages/Admin.php';
