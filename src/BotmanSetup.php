<?php

namespace PrevailExcel\BotManStudioInstaller;

class BotmanSetup
{
    protected static $basePath;

    public static function start($laravelVersion)
    {
        // Get the full path of the project folder
        self::$basePath = getcwd(); // Resolves relative to the current working directory

        echo self::$basePath;

        // Check if the directory is valid
        if (self::$basePath === false) {
            echo "Error: Invalid project folder.\n";
            return;
        }

        echo "Configuring BotMan for project at: " . self::$basePath . "\n";

        static::updateEnvironmentFile();
        static::createBotmanRouteFile($laravelVersion);
        static::createBotmanController();
        static::createExampleConversation();
        static::configureCsrfException($laravelVersion);
        static::createTinkerInterface();

        echo "BotMan setup completed for Laravel $laravelVersion!\n";
    }

    protected static function updateEnvironmentFile()
    {
        $envPath = self::$basePath . '/.env';
        if (file_exists($envPath)) {
            file_put_contents($envPath, "\nTELEGRAM_TOKEN=\n", FILE_APPEND);
        }
    }

    protected static function createBotmanRouteFile()
    {
        // Path for botman.php
        $botmanPath = self::$basePath . '/routes/botman.php';

        // Content for botman.php
        $botmanContent = <<<EOT
    <?php
    
    use BotMan\BotMan\BotMan;
    use BotMan\BotMan\Messages\Incoming\Answer;
    
    /** @var BotMan \$botman */
    \$botman = resolve('botman');
    
    \$botman->hears('hello', function (BotMan \$bot) {
        // Ask the user for their name
        \$bot->ask('Hello! What is your name?', function (Answer \$answer) use (\$bot) {
            \$name = \$answer->getText(); // Get the user's name
            \$bot->reply("Welcome, \$name! How can I assist you today?");
        });
    });
    
    \$botman->fallback(function (BotMan \$bot) {
        \$bot->reply("Sorry, I didn't understand that.");
    });
    EOT;

        // Write botman.php
        file_put_contents($botmanPath, $botmanContent);

        // Append routes to web.php
        static::addRouteToWebPhp();
    }

    protected static function addRouteToWebPhp()
    {
        $webRoutePath = self::$basePath . '/routes/web.php';

        // Content to append to web.php
        $webRouteContent = <<<EOT
    
    Route::get('/chat', function () {
        return view('tinker');
    });
    
    Route::any('/botman', [App\Http\Controllers\BotManController::class, 'handle']);
    
    EOT;

        // Append to web.php if not already included
        if (!str_contains(file_get_contents($webRoutePath), '/botman')) {
            file_put_contents($webRoutePath, $webRouteContent, FILE_APPEND);
        }
    }

    protected static function createBotmanController()
    {
        $controllerPath = self::$basePath . '/app/Http/Controllers/BotManController.php';

        $controllerContent = <<<PHP
<?php

namespace App\Http\Controllers;

use BotMan\BotMan\BotMan;
use BotMan\BotMan\Drivers\DriverManager;
use Illuminate\Http\Request;

class BotManController extends Controller
{
    public function handle()
    {
        DriverManager::loadDriver(\BotMan\Drivers\Telegram\TelegramDriver::class);
        \$botman = app('botman');
        \$botman->hears('hi', function (BotMan \$bot) {
            \$bot->reply('Hello from Laravel BotMan!');
        });

        \$botman->hears('hello', function (BotMan \$bot) {
            // Ask the user for their name
            \$bot->ask('Hello! What is your name?', function (Answer \$answer) use (\$bot) {
                \$name = \$answer->getText(); // Get the user's name
                \$bot->reply("Welcome, \$name! How can I assist you today?");
            });
        });

        \$botman->fallback(function (BotMan \$bot) {
            \$bot->reply("Sorry, I didn't understand that.");
        });

        \$botman->listen();
    }
}
PHP;

        file_put_contents($controllerPath, $controllerContent);
    }

    protected static function createExampleConversation()
    {
        $conversationContent = <<<PHP
<?php

namespace App\Conversations;

use BotMan\BotMan\Messages\Conversations\Conversation;

class ExampleConversation extends Conversation
{
    public function run()
    {
        \$this->ask('What is your name?', function(\$answer) {
            \$this->say('Nice to meet you, ' . \$answer->getText());
        });
    }
}
PHP;
        // Define the path to the Conversations directory
        $conversationsDirectory = base_path('app/Conversations');

        // Check if the directory exists
        if (!is_dir($conversationsDirectory)) {
            // Create the directory with appropriate permissions (755 is a good default)
            mkdir($conversationsDirectory, 0755, true);
        }

        // Now attempt to create the ExampleConversation.php file
        $filePath = $conversationsDirectory . '/ExampleConversation.php';

        if (file_put_contents($filePath, $conversationContent) === false) {
            throw new \Exception("Failed to create the ExampleConversation.php file at: {$filePath}");
        }
    }

    protected static function configureCsrfException($laravelVersion)
    {
        
        if (version_compare($laravelVersion, '11', '>=')) {
            $bootstrapAppPath = self::$basePath . '/bootstrap/app.php';
        
            if (file_exists($bootstrapAppPath)) {
                $appContent = file_get_contents($bootstrapAppPath);
        
                // Check if 'withMiddleware' exists in the content
                if (str_contains($appContent, 'withMiddleware(function (Middleware $middleware) {')) {
                    // Check if the CSRF exception for 'botman' already exists in the middleware block
                    if (!str_contains($appContent, "validateCsrfTokens(except: ['botman'])")) {
                        // Add the CSRF exception inside the 'withMiddleware' block
                        $appContent = preg_replace_callback(
                            '/withMiddleware\(function \(Middleware \$middleware\) \{[^}]*\}/s',
                            function ($matches) {
                                $middlewareContent = $matches[0];
                                // Add the CSRF exception for botman if it's not already there
                                $middlewareContent = rtrim($middlewareContent, '}') . "
                                    \$middleware->validateCsrfTokens(except: [
                                        'botman', // Your route path
                                    ]);\n}";
                                return $middlewareContent;
                            },
                            $appContent
                        );
                        echo "'botman' added to CSRF exception inside the existing withMiddleware block.\n";
                    } else {
                        echo "'botman' CSRF exception already exists inside the 'withMiddleware' block.\n";
                    }
                } else {
                    // If 'withMiddleware' does not exist, add the full middleware block
                    $middlewareCode = <<<PHP
        
                ->withMiddleware(function (Middleware \$middleware) {
                    \$middleware->validateCsrfTokens(except: [
                        'botman', // Your route path
                    ]);
                })
                        
        PHP;
                    // Append the middleware code
                    file_put_contents($bootstrapAppPath, $middlewareCode, FILE_APPEND);
                    echo "Full CSRF exception added for 'botman'.\n";
                }
        
                // Save the modified content back to the file
                if (isset($appContent)) {
                    file_put_contents($bootstrapAppPath, $appContent);
                }
            } else {
                echo "Error: bootstrap/app.php not found.\n";
            }
        } else {
            $csrfMiddlewarePath = self::$basePath . '/app/Http/Middleware/VerifyCsrfToken.php';

            if (file_exists($csrfMiddlewarePath)) {
                $middlewareContent = file_get_contents($csrfMiddlewarePath);

                // Check if 'botman' is already in the $except array
                if (!str_contains($middlewareContent, "'botman'")) {
                    $middlewareContent = preg_replace_callback(
                        '/protected \$except = \[(.*?)\];/s',
                        function ($matches) {
                            $existingContent = trim($matches[1]);
                            $updatedContent = $existingContent;
                            if (!empty($existingContent)) {
                                $updatedContent .= "\n        'botman', // Your route path";
                            } else {
                                $updatedContent = "'botman', // Your route path";
                            }
                            return "protected \$except = [\n        $updatedContent\n    ];";
                        },
                        $middlewareContent
                    );

                    // If the $except array doesn't exist, add it
                    if (!str_contains($middlewareContent, 'protected $except')) {
                        $exceptCode = <<<PHP

protected \$except = [
    'botman', // Your route path
];

PHP;
                        $middlewareContent = preg_replace(
                            '/(class VerifyCsrfToken extends Middleware\s*{)/',
                            "$1\n" . $exceptCode,
                            $middlewareContent
                        );
                    }

                    // Write the updated content back to the file
                    file_put_contents($csrfMiddlewarePath, $middlewareContent);
                }
            } else {
                echo "Error: VerifyCsrfToken.php not found.\n";
            }
        }
    }


    protected static function createTinkerInterface()
    {
        $viewPath = self::$basePath . '/resources/views/tinker.blade.php';
        $welcomePath = self::$basePath . '/resources/views/welcome.blade.php';

        $viewContent = <<<HTML
<!doctype html>
<html>
<head>
    <title>BotMan Widget</title>
    <meta charset="UTF-8">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/botman-web-widget@0/build/assets/css/chat.min.css">
</head>
<body>
<script id="botmanWidget" src='https://cdn.jsdelivr.net/npm/botman-web-widget@0/build/js/chat.js'></script>
<script>
    var botmanWidget = {
        frameEndpoint: '/chat'
    };
</script>
</body>
</html>
HTML;

        // Create directory if it doesn't exist
        $viewsPath = self::$basePath . '/resources/views';
        if (!file_exists($viewsPath)) {
            mkdir($viewsPath, 0755, true);
        }

        // Write the tinker view
        file_put_contents($viewPath, $viewContent);


        if (file_exists($welcomePath)) {
            $welcomeContent = file_get_contents($welcomePath);


            if (!str_contains($welcomeContent, "botmanWidget")) {
                $scriptToAdd = <<<HTML
<script src='https://cdn.jsdelivr.net/npm/botman-web-widget@0/build/js/widget.js'></script>
<script>
    var botmanWidget = {
        frameEndpoint: '/chat'
    };
</script>
</html>
HTML;

                $welcomeContent = str_replace('</html>', $scriptToAdd, $welcomeContent);
                file_put_contents($welcomePath, $welcomeContent);
            }
        }
    }
}
