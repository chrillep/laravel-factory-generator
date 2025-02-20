<?php

declare(strict_types=1);

namespace TheDoctor0\LaravelFactoryGenerator\Console;

use Exception;
use SplFIleInfo;
use SplFileObject;
use ReflectionClass;
use ReflectionMethod;
use Illuminate\Support\Str;
use Doctrine\DBAL\Types\Type;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Contracts\View\Factory;
use Illuminate\Database\Eloquent\Model;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Illuminate\Database\Eloquent\Relations\Relation;
use TheDoctor0\LaravelFactoryGenerator\Types\EnumType;
use TheDoctor0\LaravelFactoryGenerator\Database\EnumValues;

class GenerateFactoryCommand extends Command
{
    /**
     * @var string
     */
    protected $name = 'generate:factory';

    /**
     * @var string
     */
    protected $description = 'Generate test factories for models';

    /**
     * @var string
     */
    protected $dir;

    /**
     * @var string
     */
    protected $namespace;

    /**
     * @var bool
     */
    protected $force;

    /**
     * @var bool
     */
    protected $recursive;

    /**
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * @var \Illuminate\Contracts\View\Factory
     */
    protected $view;

    /**
     * @var array
     */
    protected $properties = [];

    /**
     * @throws \Doctrine\DBAL\DBALException
     */
    public function __construct(Filesystem $files, Factory $view)
    {
        parent::__construct();

        $this->files = $files;
        $this->view = $view;

        if (! Type::hasType('customEnum')) {
            Type::addType('customEnum', EnumType::class);
        }
    }

    public function handle(): void
    {
        $this->dir = $this->option('dir') ?? $this->defaultModelsDir();
        $this->namespace = $this->option('namespace');
        $this->force = $this->option('force');
        $this->recursive = $this->option('recursive');

        $models = $this->loadModels($this->argument('model'));

        foreach ($models as $model) {
            $class = class_basename($model);
            $filename = "database/factories/{$class}Factory.php";

            $class = $this->generateClassName($model);

            if ($this->recursive) {
                $filename = $this->generateRecursiveFileName($class);
                $this->makeDirRecursively($class);
            }

            if (! $this->force && $this->files->exists($filename)) {
                $this->warn("Model factory exists, use --force to overwrite: $filename");

                continue;
            }

            $content = $this->generateFactory($class);

            if (! $content) {
                continue;
            }

            if (! $this->files->put($filename, $content)) {
                $this->error("Failed to save model factory: $filename");
            } else {
                $this->info("Model factory created: $filename");
            }
        }
    }

    protected function getArguments(): array
    {
        return [
            ['model', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Which models to include', []],
        ];
    }

    protected function getOptions(): array
    {
        return [
            ['dir', 'D', InputOption::VALUE_OPTIONAL, 'The model directory', $this->dir],
            ['force', 'F', InputOption::VALUE_NONE, 'Overwrite any existing model factory'],
            ['namespace', 'N', InputOption::VALUE_OPTIONAL, 'Model Namespace'],
            ['recursive', 'R', InputOption::VALUE_NONE, 'Generate model factory recursively']
        ];
    }

    protected function generateFactory(string $model): ?string
    {
        if (! $this->existsClassOrTrait($model)) {
            $this->error("Unable to find $model class!");

            return null;
        }

        $this->properties = [];

        try {
            $reflection = new ReflectionClass($model);

            if (! $this->isInstantiableModelClass($reflection)) {
                return null;
            }

            $eloquentModel = $this->laravel->make($model);

            $this->getPropertiesFromTable($eloquentModel);
            $this->getPropertiesFromMethods($eloquentModel);

            return "<?php\n\n{$this->createFactory($reflection)}";
        } catch (Exception $e) {
            $this->error("Could not analyze class $model.\nException: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * @noinspection PhpUndefinedMethodInspection
     */
    protected function loadModels(array $models = []): array
    {
        $rootDirectory = basename($this->laravel->path());

        if (! empty($models)) {
            return array_map(function ($name) use ($rootDirectory) {
                if (Str::contains($name, '\\')) {
                    return $name;
                }

                return str_replace(
                    ['/', DIRECTORY_SEPARATOR, "$rootDirectory\\"],
                    ['\\', '\\', $this->laravel->getNamespace()],
                    $this->formatPath($this->dir, $name)
                );
            }, $models);
        }

        if (! file_exists($this->laravel->basePath($this->dir))) {
            $this->error('Model directory does not exists.');

            return [];
        }

        return array_map(function (SplFIleInfo $file) use ($rootDirectory) {
            return str_replace(
                ['/', DIRECTORY_SEPARATOR, "$rootDirectory\\"],
                ['\\', '\\', $this->laravel->getNamespace()],
                $this->formatPath($file->getPath(), basename($file->getFilename(), '.php'))
            );
        }, $this->files->allFiles($this->dir));
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function getPropertiesFromTable(Model $model): void
    {
        $table = $model->getConnection()->getTablePrefix() . $model->getTable();

        try {
            $schema = $model->getConnection()->getDoctrineSchemaManager();
        } catch (Exception $exception) {
            $class = get_class($model);
            $driver = $model->getConnection()->getDriverName();

            if (in_array($driver, ['mysql', 'pgsql', 'sqlite'])) {
                $this->error("Database driver ($driver) for $class model is not configured properly!");
            } else {
                $this->warn("Database driver ($driver) for $class model is not supported.");
            }

            return;
        }

        $database = null;
        if (Str::contains($table, '.')) {
            [$database, $table] = explode('.', $table);
        }

        $this->registerCustomTypes($schema);

        $columns = $schema->listTableColumns($table, $database);

        if (! $columns) {
            $tenantModel = $this->laravel['config']->get('tenancy.tenant_model', null);
            if ($tenantModel ) {
                tenancy()->initialize($tenantModel::first());
                $table = $model->getConnection()->getTablePrefix() . $model->getTable();
                $schema = $model->getConnection()->getDoctrineSchemaManager();
                $columns = $schema->listTableColumns($table, $database);
            }
        }

        if (! $columns) {
            return;
        }

        foreach ($columns as $column) {
            $field = $column->getName();

            if (in_array($field, $model->getDates(), true)) {
                $type = 'datetime';
            } else {
                $type = $column->getType()->getName();
            }

            if ($this->isFieldFakeable($field, $model)) {
                $this->setProperty($model, $field, $type);
            }
        }
    }

    /**
     * @throws \ReflectionException
     */
    protected function getPropertiesFromMethods(Model $model): void
    {
        $methods = get_class_methods($model);

        foreach ($methods as $method) {
            if (Str::startsWith($method, 'get') || method_exists(Model::class, $method)) {
                continue;
            }

            $reflection = new ReflectionMethod($model, $method);

            $file = new SplFileObject($reflection->getFileName());
            $file->seek($reflection->getStartLine() - 1);

            $code = '';

            while ($file->key() < $reflection->getEndLine()) {
                $code .= $file->current();
                $file->next();
            }

            $code = trim(preg_replace('/\s\s+/', '', $code));
            $start = strpos($code, 'function ');
            $code = substr($code, $start, strrpos($code, '}') - $start + 1);

            $search = '$this->belongsTo(';

            if (! Str::contains($code, $search)) {
                continue;
            }

            /** @var \Illuminate\Database\Eloquent\Relations\BelongsTo $relationObject */
            $relationObject = $model->$method();

            if ($relationObject instanceof Relation) {
                $this->properties[$relationObject->getForeignKeyName()] = $this->factoryClass($relationObject);
            }
        }
    }

    protected function setProperty(Model $model, string $field, string $type): void
    {
        if ($enumValues = EnumValues::get($model, $field)) {
            $enumValues = implode("', '", $enumValues);

            $this->properties[$field] = $this->fakerPrefix("randomElement(['$enumValues'])");

            return;
        }

        if ($property = $this->mapByName($field)) {
            $this->properties[$field] = $property;

            return;
        }

        if ($property = $this->mapByType($type)) {
            $this->properties[$field] = $property;

            return;
        }

        $this->properties[$field] = $this->mapByType('string');
    }

    protected function createFactory(ReflectionClass $reflection): string
    {
        return $this->view->make($this->factoryView(), [
            'properties' => $this->properties,
            'name' => $reflection->getName(),
            'shortName' => $reflection->getShortName(),
            'namespace' => 'Database\\Factories' . $this->generateAdditionalNameSpace($reflection->getName()),
        ])->render();
    }

    protected function defaultModelsDir(): string
    {
        return $this->isLaravel8OrAbove()
            ? $this->formatPath('app', 'Models')
            : 'app';
    }

    protected function factoryView(): string
    {
        return $this->isLaravel8OrAbove()
            ? 'factory-generator::class-factory'
            : 'factory-generator::method-factory';
    }

    protected function factoryClass(Relation $relation): string
    {
        $class = get_class($relation->getRelated());

        return $this->isLaravel8OrAbove()
            ? "\\$class::factory()"
            : "factory($class::class)";
    }

    protected function fakerPrefix(string $type): string
    {
        return $this->isLaravel8OrAbove()
            ? "\$this->faker->$type"
            : "\$faker->$type";
    }

    protected function isFieldFakeable(string $field, Model $model): bool
    {
        return ! ($model->incrementing && $field === $model->getKeyName())
            && ! ($model->timestamps && ($field === $model::CREATED_AT || $field === $model::UPDATED_AT))
            && ! (method_exists($model, 'getDeletedAtColumn') && $field === $model->getDeletedAtColumn());
    }

    protected function mapByName(string $field): ?string
    {
        $fakeableNames = [
            'language' => $this->fakerPrefix('languageCode'),
            'lang' => $this->fakerPrefix('languageCode'),
            'locale' => $this->fakerPrefix('locale'),
            'city' => $this->fakerPrefix('city'),
            'town' => $this->fakerPrefix('city'),
            'town_city' => $this->fakerPrefix('city'),
            'state' => $this->fakerPrefix('state'),
            'region' => $this->fakerPrefix('state'),
            'region_state' => $this->fakerPrefix('state'),
            'company' => $this->fakerPrefix('company'),
            'country' => $this->fakerPrefix('country'),
            'description' => $this->fakerPrefix('text'),
            'email' => $this->fakerPrefix('safeEmail'),
            'email_address' => $this->fakerPrefix('safeEmail'),
            'first_name' => $this->fakerPrefix('firstName'),
            'firstname' => $this->fakerPrefix('firstName'),
            'last_name' => $this->fakerPrefix('lastName'),
            'lastname' => $this->fakerPrefix('lastName'),
            'name' => $this->fakerPrefix('name'),
            'full_name' => $this->fakerPrefix('name'),
            'lat' => $this->fakerPrefix('latitude'),
            'latitude' => $this->fakerPrefix('latitude'),
            'lng' => $this->fakerPrefix('longitude'),
            'longitude' => $this->fakerPrefix('longitude'),
            'password' => "bcrypt({$this->fakerPrefix('password')})",
            'phone' => $this->fakerPrefix('phoneNumber'),
            'telephone' => $this->fakerPrefix('phoneNumber'),
            'phone_number' => $this->fakerPrefix('phoneNumber'),
            'postcode' => $this->fakerPrefix('postcode'),
            'postal_code' => $this->fakerPrefix('postcode'),
            'zip' => $this->fakerPrefix('postcode'),
            'zip_postal_code' => $this->fakerPrefix('postcode'),
            'slug' => $this->fakerPrefix('slug'),
            'street' => $this->fakerPrefix('streetName'),
            'address' => $this->fakerPrefix('address'),
            'address1' => $this->fakerPrefix('streetAddress'),
            'address2' => $this->fakerPrefix('secondaryAddress'),
            'summary' => $this->fakerPrefix('text'),
            'title' => $this->fakerPrefix('title'),
            'subject' => $this->fakerPrefix('title'),
            'note' => $this->fakerPrefix('sentence'),
            'sentence' => $this->fakerPrefix('sentence'),
            'url' => $this->fakerPrefix('url'),
            'link' => $this->fakerPrefix('url'),
            'href' => $this->fakerPrefix('url'),
            'domain' => $this->fakerPrefix('domainName'),
            'user_name' => $this->fakerPrefix('userName'),
            'username' => $this->fakerPrefix('userName'),
            'currency' => $this->fakerPrefix('currencyCode'),
            'guid' => $this->fakerPrefix('uuid'),
            'uuid' => $this->fakerPrefix('uuid'),
            'iban' => $this->fakerPrefix('iban()'),
            'mac' => $this->fakerPrefix('macAddress'),
            'ip' => $this->fakerPrefix('ipv4'),
            'ipv4' => $this->fakerPrefix('ipv4'),
            'ipv6' => $this->fakerPrefix('ipv6'),
            'request_ip' => $this->fakerPrefix('ipv4'),
            'user_agent' => $this->fakerPrefix('userAgent'),
            'request_user_agent' => $this->fakerPrefix('userAgent'),
            'iso3' => $this->fakerPrefix('countryISOAlpha3'),
            'hash' => $this->fakerPrefix('sha256'),
            'sha256' => $this->fakerPrefix('sha256'),
            'sha256_hash' => $this->fakerPrefix('sha256'),
            'sha1' => $this->fakerPrefix('sha1'),
            'sha1_hash' => $this->fakerPrefix('sha1'),
            'md5' => $this->fakerPrefix('md5'),
            'md5_hash' => $this->fakerPrefix('md5'),
            'remember_token' => 'Str::random(10)',
        ];

        return $fakeableNames[$field] ?? null;
    }

    protected function mapByType(string $field): ?string
    {
        $fakeableTypes = [
            'string' => $this->fakerPrefix('word'),
            'text' => $this->fakerPrefix('text'),
            'date' => $this->fakerPrefix('date()'),
            'time' => $this->fakerPrefix('time()'),
            'guid' => $this->fakerPrefix('uuid'),
            'datetimetz' => $this->fakerPrefix('dateTime()'),
            'datetime' => $this->fakerPrefix('dateTime()'),
            'integer' => $this->fakerPrefix('randomNumber()'),
            'bigint' => $this->fakerPrefix('randomNumber()'),
            'smallint' => $this->fakerPrefix('randomNumber()'),
            'decimal' => $this->fakerPrefix('randomFloat()'),
            'float' => $this->fakerPrefix('randomFloat()'),
            'boolean' => $this->fakerPrefix('boolean'),
        ];

        return $fakeableTypes[$field] ?? null;
    }

    protected function formatPath(string ...$paths): string
    {
        return implode(DIRECTORY_SEPARATOR, $paths);
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function registerCustomTypes(AbstractSchemaManager $schema): void
    {
        $platform = $schema->getDatabasePlatform();

        if (! $platform) {
            return;
        }

        $platform->registerDoctrineTypeMapping('enum', 'customEnum');
        $platformName = $platform->getName();
        $customTypes = $this->laravel['config']->get("ide-helper.custom_db_types.$platformName", []);

        foreach ($customTypes as $typeName => $doctrineTypeName) {
            $platform->registerDoctrineTypeMapping($typeName, $doctrineTypeName);
        }
    }

    protected function isLaravel8OrAbove(): bool
    {
        preg_match('/\d\.\d(\.\d|-dev)/', $this->laravel->version(), $version);

        return (int) ($version[0] ?? $this->laravel->version())[0] >= 8;
    }

    protected function getFileStructureDiff(string $class): array
    {
        if ($this->namespace) {
            return array_diff(explode('\\', $class), explode('\\', $this->namespace));
        }

        return array_diff(explode('\\', $class), explode('/', ucfirst($this->dir)));
    }

    protected function generateRecursiveFileName(string $class): string
    {
        return 'database/factories/' . implode('/', $this->getFileStructureDiff($class)) . 'Factory.php';
    }

    protected function generateClassName(string $model): string
    {
        $filePathDiff = $this->getFileStructureDiff($model);

        return $this->namespace ? $this->namespace . '\\' . implode('\\', $filePathDiff) : $model;
    }

    protected function generateAdditionalNameSpace(string $class): string
    {
        $append = '';

        $filePathDiff = $this->getFileStructureDiff($class);
        array_pop($filePathDiff);

        if ($this->recursive && !empty($filePathDiff)) {
            $append = '\\' . implode('\\', $filePathDiff);
        }

        return $append;
    }

    protected function makeDirRecursively(string $class, int $permission = 0755): void
    {
        if (! $this->existsClassOrTrait($class)) {
            return;
        }

        try {
            $reflection = new ReflectionClass($class);
            $dir = 'database/factories' . str_replace('\\', '/', $this->generateAdditionalNameSpace($class));

            if (!file_exists($dir) && $this->isInstantiableModelClass($reflection)) {
                mkdir($dir, $permission, true);
            }
        } catch (Exception $e) {
            $this->error("Could not analyze class $class.\nException: {$e->getMessage()}");
        }
    }

    protected function isInstantiableModelClass(ReflectionClass $reflection): bool
    {
        return $reflection->isSubclassOf(Model::class) && $reflection->IsInstantiable();
    }

    protected function existsClassOrTrait(string $class): bool
    {
        return class_exists($class) || trait_exists($class);
    }
}
