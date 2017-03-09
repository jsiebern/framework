<?php
namespace Herbert\Framework;

use Illuminate\Database\Capsule\Manager as Capsule;

class Migration {
    /**
     * @var Illuminate\Database\Capsule\Manager
     */
    private $schema;

    /**
     * @var array
     */
    private $migrations = [];

    /**
     * @var \Herbert\Framework\Application
     */
    protected $app;

    /**
     * The current namespace.
     *
     * @var string|null
     */
    protected $namespace = null;

    /**
     * @param \Herbert\Framework\Application $app
     */
    public function __construct(Application $app) {
        $this->app = $app;
        $this->schema = Capsule::schema();
    }
    
    public function add($name) {
        $migrationStep = new MigrationStep($name, $this->namespace);
        $this->migrations[] = $migrationStep;

        return $migrationStep;
    }

    public function executeMigration() {
        if (!$this->namespace) {
            return;
        }
        $existing = $this->getExistingMigrations();

        foreach ($this->migrations as $migration) {
            $namespace = $migration->getNamespace();
            if ($namespace != $this->namespace) {
                continue;
            }

            $name = $migration->getName();
            $migration_slug = $namespace.'|'.$name;

            if (!in_array($migration_slug, $existing)) {
                $existing[] = $migration_slug;

                $up = $migration->getUp();
                if ($up) {
                    call_user_func($up, $this->schema);
                }
            }
        }

        $this->setExistingMigrations($existing);
    }

    public function executeDeletion() {
        if (!$this->namespace) {
            return;
        }
        
        foreach ($this->migrations as $migration) {
            if ($migration->getNamespace() != $this->namespace) {
                continue;
            }
            $delete = $migration->getDelete();
            if ($delete) {
                call_user_func($delete, $this->schema);
            }
        }

        $this->resetExistingMigrations();
    }

    private function setExistingMigrations($existing) {
        if (get_option($this->getExistingName())) {
            update_option($this->getExistingName(), serialize($existing));
        }
        else {
            add_option($this->getExistingName(), serialize($existing));
        }
    }

    private function getExistingMigrations() {
        return unserialize(
            get_option($this->getExistingName(), serialize([]))
        );
    }

    public function resetExistingMigrations() {
        delete_option($this->getExistingName());
    }

    private function getExistingName() {
        return $this->namespace.'_migrations';
    }

    /**
     * Sets the current namespace.
     *
     * @param  string $namespace
     * @return void
     */
    public function setNamespace($namespace)
    {
        $this->namespace = $namespace;
    }

    /**
     * Unsets the current namespace.
     *
     * @return void
     */
    public function unsetNamespace()
    {
        $this->namespace = null;
    }

    /**
     * Namespaces a name.
     *
     * @param  string $as
     * @return string
     */
    protected function namespaceAs($as)
    {
        if ($this->namespace === null)
        {
            return $as;
        }

        return $this->namespace . '::' . $as;
    }
}

class MigrationStep {
    private $name;
    private $namespace;
    private $upFunction = null;
    private $downFunction = null;
    private $deleteFunction = null;

    public function __construct($name, $namespace) {
        $this->name = $name;
        $this->namespace = $namespace;

        return $this;
    }

    public function up($function) {
        $this->upFunction = $function;
        return $this;
    }

    public function down($function) {
        $this->downFunction = $function;
        return $this;
    }

    public function delete($function) {
        $this->deleteFunction = $function;
        return $this;
    }

    public function getUp() {
        return $this->upFunction;
    }

    public function getDown() {
        return $this->downFunction;
    }

    public function getDelete() {
        return $this->deleteFunction;
    }

    public function getNamespace() {
        return $this->namespace;
    }

    public function getName() {
        return $this->name;
    }
}