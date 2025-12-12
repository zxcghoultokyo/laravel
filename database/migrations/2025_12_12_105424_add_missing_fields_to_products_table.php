Finished
Build logs
30s


Preparing build environment

Finished

3s


Running build commands

Finished

9s


Pushing application

Finished

0s

Failed
Deployment logs
10s


Preparing deploy environment

Finished

1s


Running deploy commands

Failed

00:00:32
Running deploy commands
Failed
Deploy commands required
Running deploy commands...

   INFO  Running migrations.  

  2025_12_12_105424_add_missing_fields_to_products_table ....... 124.42ms FAIL
{"message":"SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name 'products_popularity_index' (Connection: mysql, SQL: alter table `products` add index `products_popularity_index`(`popularity`))","context":{"exception":{"class":"Illuminate\\Database\\QueryException","message":"SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name 'products_popularity_index' (Connection: mysql, SQL: alter table `products` add index `products_popularity_index`(`popularity`))","code":42000,"file":"/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:826","trace":["/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:780","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:559","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Schema/Blueprint.php:121","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Schema/Builder.php:650","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Schema/Builder.php:492","/var/www/html/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php:363","/var/www/html/database/migrations/2025_12_12_105424_add_missing_fields_to_products_table.php:10","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:514","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:439","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:448","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:250","/var/www/html/vendor/laravel/framework/src/Illuminate/Console/View/Components/Task.php:41","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:809","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:250","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:210","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:137","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Console/Migrations/MigrateCommand.php:116","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:666","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Console/Migrations/MigrateCommand.php:109","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Console/Migrations/MigrateCommand.php:88","/var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:36","/var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php:43","/var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:96","/var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:35","/var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php:799","/var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php:211","/var/www/html/vendor/symfony/console/Command/Command.php:341","/var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php:180","/var/www/html/vendor/symfony/console/Application.php:1102","/var/www/html/vendor/symfony/console/Application.php:356","/var/www/html/vendor/symfony/console/Application.php:195","/var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Console/Kernel.php:197","/var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php:1235","/var/www/html/artisan:16"],"previous":{"class":"PDOException","message":"SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name 'products_popularity_index'","code":42000,"file":"/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:570","trace":["/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:570","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:813","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:780","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Connection.php:559","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Schema/Blueprint.php:121","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Schema/Builder.php:650","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Schema/Builder.php:492","/var/www/html/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php:363","/var/www/html/database/migrations/2025_12_12_105424_add_missing_fields_to_products_table.php:10","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:514","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:439","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:448","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:250","/var/www/html/vendor/laravel/framework/src/Illuminate/Console/View/Components/Task.php:41","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:809","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:250","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:210","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:137","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Console/Migrations/MigrateCommand.php:116","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:666","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Console/Migrations/MigrateCommand.php:109","/var/www/html/vendor/laravel/framework/src/Illuminate/Database/Console/Migrations/MigrateCommand.php:88","/var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:36","/var/www/html/vendor/laravel/framework/src/Illuminate/Container/Util.php:43","/var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:96","/var/www/html/vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php:35","/var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php:799","/var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php:211","/var/www/html/vendor/symfony/console/Command/Command.php:341","/var/www/html/vendor/laravel/framework/src/Illuminate/Console/Command.php:180","/var/www/html/vendor/symfony/console/Application.php:1102","/var/www/html/vendor/symfony/console/Application.php:356","/var/www/html/vendor/symfony/console/Application.php:195","/var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Console/Kernel.php:197","/var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Application.php:1235","/var/www/html/artisan:16"]}}},"level":400,"level_name":"ERROR","channel":"production","datetime":"2025-12-12T10:55:31.602618+00:00","extra":{}}

In Connection.php line 826:
                                                                               
  SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name   
  'products_popularity_index' (Connection: mysql, SQL: alter table `products`  
   add index `products_popularity_index`(`popularity`))                        
                                                                               

In Connection.php line 570:
                                                                               
  SQLSTATE[42000]: Syntax error or access violation: 1061 Duplicate key name   
  'products_popularity_index'                                                  
                                                                               

Deploy commands failed!
