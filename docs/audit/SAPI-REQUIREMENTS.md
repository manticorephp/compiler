# SAPI requirements

Derived by `tools/audit/requirements.php` from what symfony-demo actually
references. Do not hand-edit — regenerate.

This is the input list for the SAPI epic. It is what a request-scoped
runtime has to provide before `Request::createFromGlobals()` and
`Response::send()` mean anything. Note the audit could measure the whole
web stack WITHOUT any of it — `Request::create()` reads no superglobals —
so this surface is needed to SERVE, not to test.

## Superglobal keys read

### `$_SERVER` — 60 distinct

| key | first site |
|---|---|
| `<whole array>` | vendor/monolog/monolog/src/Monolog/Handler/PHPConsoleHandler.php |
| `ALL_PROXY` | vendor/symfony/http-client/HttpClientTrait.php:844 |
| `APP_BUILD_DIR` | vendor/symfony/dependency-injection/Kernel/KernelTrait.php:63 |
| `APP_CACHE_DIR` | vendor/symfony/dependency-injection/Kernel/KernelTrait.php:54 |
| `APP_DEBUG` | tests/bootstrap.php:20 |
| `APP_ENV` | tests/object-manager.php:19 |
| `APP_LOG_DIR` | vendor/symfony/dependency-injection/Kernel/KernelTrait.php:86 |
| `APP_RUNTIME` | vendor/autoload_runtime.php:18 |
| `APP_RUNTIME_OPTIONS` | vendor/autoload_runtime.php:15 |
| `APP_SHARE_DIR` | vendor/symfony/dependency-injection/Kernel/KernelTrait.php:72 |
| `COMPOSER_PREFER_DEV_OVER_PRERELEASE` | vendor/symfony/flex/src/Flex.php:208 |
| `COMPOSE_FILE` | vendor/symfony/flex/src/Configurator/DockerComposeConfigurator.php:172 |
| `COMPOSE_PATH_SEPARATOR` | vendor/symfony/flex/src/Configurator/DockerComposeConfigurator.php:174 |
| `CONTENT_LENGTH` | vendor/symfony/form/Util/ServerParams.php:87 |
| `DOCTRINE_DEPRECATIONS` | vendor/doctrine/deprecations/src/Deprecation.php:293 |
| `FORCE_COLOR` | vendor/symfony/console/Output/StreamOutput.php:98 |
| `FRANKENPHP_LOOP_MAX` | vendor/symfony/runtime/SymfonyRuntime.php:169 |
| `FRANKENPHP_WORKER` | vendor/symfony/runtime/SymfonyRuntime.php:182 |
| `HTTPS` | vendor/symfony/security-csrf/CsrfTokenManager.php:35 |
| `HTTPS_PROXY` | vendor/symfony/http-client/HttpClientTrait.php:847 |
| `HTTP_` | vendor/symfony/http-foundation/Request.php:611 |
| `HTTP_ACCEPT` | vendor/symfony/var-dumper/VarDumper.php:98 |
| `HTTP_CONTENT_TYPE` | vendor/symfony/http-client-contracts/Test/Fixtures/web/index.php:12 |
| `HTTP_IF_NONE_MATCH` | vendor/symfony/http-client-contracts/Test/Fixtures/web/index.php:120 |
| `HTTP_PROXY` | vendor/symfony/http-client/HttpClientTrait.php:844 |
| `HTTP_USER_AGENT` | vendor/monolog/monolog/src/Monolog/Handler/ChromePHPHandler.php:180 |
| `HTTP_X_FIREPHP_VERSION` | vendor/monolog/monolog/src/Monolog/Handler/FirePHPHandler.php:172 |
| `HTTP_X_HTTP_METHOD_OVERRIDE` | vendor/symfony/form/NativeRequestHandler.php:176 |
| `IDEA_INITIAL_DIRECTORY` | vendor/symfony/console/Formatter/OutputFormatterStyle.php:81 |
| `KERNEL_CLASS` | vendor/symfony/framework-bundle/Test/KernelTestCase.php:62 |
| `NO_COLOR` | vendor/symfony/console/Output/StreamOutput.php:93 |
| `NO_PROXY` | vendor/symfony/http-client/CurlHttpClient.php:116 |
| `PATH` | vendor/symfony/runtime/GenericRuntime.php:148 |
| `PHP_SELF` | vendor/symfony/console/Command/Command.php:561 |
| `Path` | vendor/symfony/runtime/GenericRuntime.php:148 |
| `QUERY_STRING` | vendor/symfony/framework-bundle/FrameworkBundle.php:239 |
| `REMOTE_ADDR` | vendor/symfony/http-foundation/Request.php:634 |
| `REQUEST_METHOD` | vendor/symfony/form/NativeRequestHandler.php:172 |
| `REQUEST_TIME` | vendor/symfony/cache/Adapter/PhpFilesAdapter.php:53 |
| `REQUEST_TIME_FLOAT` | vendor/symfony/var-dumper/Dumper/ContextProvider/CliContextProvider.php:29 |
| `REQUEST_URI` | vendor/monolog/monolog/src/Monolog/Handler/ChromePHPHandler.php:141 |
| `SCRIPT_FILENAME` | vendor/autoload_runtime.php:5 |
| `SHELL` | vendor/symfony/console/Command/DumpCompletionCommand.php:116 |
| `SHELL_VERBOSITY` | vendor/symfony/console/Application.php:213 |
| `SOURCE_DATE_EPOCH` | vendor/symfony/dependency-injection/Dumper/PhpDumper.php:151 |
| `SYMFONY_DISABLE_RESOURCE_TRACKING` | vendor/symfony/dependency-injection/Kernel/KernelTrait.php:124 |
| `SYMFONY_DOCKER` | vendor/symfony/flex/src/Configurator/DockerComposeConfigurator.php:114 |
| `SYMFONY_DOTENV_PATH` | vendor/symfony/dotenv/Dotenv.php:846 |
| `SYMFONY_DOTENV_VARS` | vendor/symfony/dotenv/Command/DebugCommand.php:118 |
| `SYMFONY_IDE` | vendor/symfony/error-handler/ErrorRenderer/FileLinkFormatter.php:38 |
| `SYMFONY_INTL_WITH_USER_ASSIGNED` | vendor/symfony/intl/Countries.php:237 |
| `SYMFONY_PATCH_TYPE_DECLARATIONS` | vendor/symfony/error-handler/DebugClassLoader.php:171 |
| `VAR_DUMPER_FORMAT` | vendor/symfony/var-dumper/VarDumper.php:55 |
| `VAR_DUMPER_SERVER` | vendor/symfony/var-dumper/VarDumper.php:74 |
| `all_proxy` | vendor/symfony/http-client/HttpClientTrait.php:844 |
| `argc` | vendor/symfony/runtime/SymfonyRuntime.php:114 |
| `argv` | vendor/symfony/console/Command/CompleteCommand.php:103 |
| `http_proxy` | vendor/symfony/http-client-contracts/Test/HttpClientTestCase.php:985 |
| `https_proxy` | vendor/symfony/http-client/HttpClientTrait.php:847 |
| `no_proxy` | vendor/symfony/http-client-contracts/Test/HttpClientTestCase.php:1006 |

### `$_GET` — 4 distinct

| key | first site |
|---|---|
| `<whole array>` | vendor/psr/http-message/src/ServerRequestInterface.php |
| `headers` | vendor/symfony/http-client-contracts/Test/Fixtures/web/index.php:215 |
| `location` | vendor/symfony/http-client-contracts/Test/Fixtures/web/index.php:104 |
| `status` | vendor/symfony/http-client-contracts/Test/Fixtures/web/index.php:212 |

### `$_POST` — 2 distinct

| key | first site |
|---|---|
| `<whole array>` | vendor/psr/http-message/src/ServerRequestInterface.php |
| `content-type` | vendor/symfony/http-client-contracts/Test/Fixtures/web/index.php:12 |

### `$_COOKIE` — 1 distinct

| key | first site |
|---|---|
| `<whole array>` | vendor/psr/http-message/src/ServerRequestInterface.php |

### `$_FILES` — 1 distinct

| key | first site |
|---|---|
| `<whole array>` | vendor/psr/http-message/src/ServerRequestInterface.php |

### `$_REQUEST` — 1 distinct

| key | first site |
|---|---|
| `<whole array>` | vendor/symfony/http-foundation/InputBag.php |

### `$_SESSION` — 1 distinct

| key | first site |
|---|---|
| `<whole array>` | vendor/monolog/monolog/src/Monolog/Handler/PHPConsoleHandler.php |

### `$_ENV` — 14 distinct

| key | first site |
|---|---|
| `<whole array>` | vendor/symfony/dotenv/Command/DotenvDumpCommand.php |
| `APP_DEBUG` | vendor/symfony/framework-bundle/Test/KernelTestCase.php:135 |
| `APP_ENV` | vendor/symfony/framework-bundle/Test/KernelTestCase.php:134 |
| `APP_RUNTIME` | vendor/autoload_runtime.php:18 |
| `APP_RUNTIME_OPTIONS` | vendor/autoload_runtime.php:15 |
| `DOCTRINE_DEPRECATIONS` | vendor/doctrine/deprecations/src/Deprecation.php:293 |
| `FRANKENPHP_LOOP_MAX` | vendor/symfony/runtime/SymfonyRuntime.php:169 |
| `KERNEL_CLASS` | vendor/symfony/framework-bundle/Test/KernelTestCase.php:62 |
| `SHELL_VERBOSITY` | vendor/symfony/console/Application.php:213 |
| `SYMFONY_DOTENV_PATH` | vendor/symfony/dotenv/Command/DotenvDumpCommand.php:123 |
| `SYMFONY_DOTENV_VARS` | vendor/symfony/dotenv/Command/DotenvDumpCommand.php:122 |
| `SYMFONY_IDE` | vendor/symfony/error-handler/ErrorRenderer/FileLinkFormatter.php:38 |
| `SYMFONY_INTL_WITH_USER_ASSIGNED` | vendor/symfony/intl/Countries.php:237 |
| `SYMFONY_PATCH_TYPE_DECLARATIONS` | vendor/symfony/error-handler/DebugClassLoader.php:171 |

## SAPI functions called

`present` is measured against this tree's stdlib; see
`docs/audit/probes/cap_sapi_fn_presence.php` for the runtime check.

| function | call sites | first site |
|---|---|---|
| `error_get_last()` | 15 | vendor/doctrine/dbal/src/Driver/IBMDB2/Connection.php:53 |
| `fastcgi_finish_request()` | 2 | vendor/symfony/http-foundation/Response.php:415 |
| `filter_var()` | 101 | vendor/composer/ClassLoader.php:367 |
| `header()` | 48 | vendor/autoload.php:7 |
| `header_remove()` | 2 | vendor/symfony/http-foundation/Response.php:352 |
| `headers_list()` | 3 | vendor/monolog/monolog/src/Monolog/Handler/BrowserConsoleHandler.php:140 |
| `headers_sent()` | 17 | vendor/autoload.php:6 |
| `http_response_code()` | 5 | vendor/monolog/monolog/src/Monolog/ErrorHandler.php:198 |
| `ignore_user_abort()` | 4 | vendor/symfony/http-client-contracts/Test/Fixtures/web/index.php:155 |
| `is_uploaded_file()` | 2 | vendor/psr/http-message/src/UploadedFileInterface.php:52 |
| `move_uploaded_file()` | 4 | vendor/psr/http-message/src/UploadedFileInterface.php:36 |
| `ob_end_clean()` | 7 | vendor/doctrine/orm/src/Tools/Debug.php:76 |
| `ob_end_flush()` | 1 | vendor/symfony/http-foundation/Response.php:1284 |
| `ob_get_clean()` | 14 | vendor/nette/utils/src/Utils/Helpers.php:32 |
| `ob_get_contents()` | 1 | vendor/doctrine/orm/src/Tools/Debug.php:74 |
| `ob_get_level()` | 9 | vendor/symfony/error-handler/ErrorRenderer/HtmlErrorRenderer.php:115 |
| `ob_start()` | 20 | vendor/doctrine/orm/src/Tools/Debug.php:71 |
| `putenv()` | 27 | vendor/symfony/console/Application.php:189 |
| `register_shutdown_function()` | 13 | vendor/monolog/monolog/src/Monolog/ErrorHandler.php:133 |
| `session_id()` | 4 | vendor/symfony/http-foundation/Session/Storage/NativeSessionStorage.php:141 |
| `session_name()` | 3 | vendor/symfony/http-foundation/Session/Storage/NativeSessionStorage.php:112 |
| `session_regenerate_id()` | 2 | vendor/symfony/http-foundation/Session/Storage/NativeSessionStorage.php:195 |
| `session_start()` | 2 | vendor/symfony/http-foundation/Session/Storage/NativeSessionStorage.php:145 |
| `session_status()` | 8 | vendor/symfony/http-foundation/Session/Storage/NativeSessionStorage.php:104 |
| `session_write_close()` | 4 | vendor/symfony/http-foundation/Session/Storage/NativeSessionStorage.php:214 |
| `set_error_handler()` | 73 | vendor/doctrine/dbal/src/Driver/PgSQL/Driver.php:34 |
| `set_exception_handler()` | 11 | vendor/monolog/monolog/src/Monolog/ErrorHandler.php:91 |
| `setcookie()` | 2 | vendor/symfony/http-foundation/Session/Storage/Handler/AbstractSessionHandler.php:115 |
| `spl_autoload_functions()` | 6 | vendor/symfony/dependency-injection/ContainerBuilder.php:296 |
| `spl_autoload_register()` | 7 | vendor/composer/ClassLoader.php:389 |
| `spl_autoload_unregister()` | 6 | vendor/composer/ClassLoader.php:410 |
| `trigger_error()` | 43 | vendor/composer/InstalledVersions.php:275 |
