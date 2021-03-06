<?php

namespace League\FactoryMuffin;

use Closure;
use Exception;
use Faker\Factory as Faker;
use League\FactoryMuffin\Exceptions\DeleteFailedException;
use League\FactoryMuffin\Exceptions\DeleteMethodNotFoundException;
use League\FactoryMuffin\Exceptions\DeletingFailedException;
use League\FactoryMuffin\Exceptions\DirectoryNotFoundException;
use League\FactoryMuffin\Exceptions\ModelNotFoundException;
use League\FactoryMuffin\Exceptions\NoDefinedFactoryException;
use League\FactoryMuffin\Exceptions\SaveFailedException;
use League\FactoryMuffin\Exceptions\SaveMethodNotFoundException;
use League\FactoryMuffin\Generators\Base as Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * This is the factory class.
 *
 * This class is not intended to be used directly, but should be used through
 * the provided facade. The only time where you should be directly calling
 * methods here should be when you're using method chaining after initially
 * using the facade.
 *
 * @package League\FactoryMuffin
 * @author  Zizaco <zizaco@gmail.com>
 * @author  Scott Robertson <scottymeuk@gmail.com>
 * @author  Graham Campbell <graham@mineuk.com>
 * @license <https://github.com/thephpleague/factory-muffin/blob/master/LICENSE> MIT
 */
class Factory
{
    /**
     * The array of factories.
     *
     * @var array
     */
    private $factories = array();

    /**
     * The array of callbacks to trigger on instance/create.
     *
     * @var array
     */
    private $callbacks = array();

    /**
     * The array of objects we have created and are pending save.
     *
     * @var array
     */
    private $pending = array();

    /**
     * The array of objects we have created and have saved.
     *
     * @var array
     */
    private $saved = array();

    /**
     * This is the method used when saving objects.
     *
     * @var string
     */
    private $saveMethod = 'save';

    /**
     * This is the method used when deleting objects.
     *
     * @var string
     */
    private $deleteMethod = 'delete';

    /**
     * This is the custom model maker closure.
     *
     * @var \Closure
     */
    private $customMaker;

    /**
     * This is the custom attribute setter closure.
     *
     * @var \Closure
     */
    private $customSetter;

    /**
     * This is the custom model saver closure.
     *
     * @var \Closure
     */
    private $customSaver;

    /**
     * This is the custom model deleter closure.
     *
     * @var \Closure
     */
    private $customDeleter;

    /**
     * The faker instance.
     *
     * @var \Faker\Generator
     */
    private $faker;

    /**
     * The faker localization.
     *
     * @var string
     */
    private $fakerLocale = 'en_EN';

    /**
     * Set the faker locale.
     *
     * @param string $local The faker locale.
     *
     * @return $this
     */
    public function setFakerLocale($local)
    {
        $this->fakerLocale = $local;

        // The faker class must be instantiated again with a new the new locale
        $this->faker = null;

        return $this;
    }

    /**
     * Set the method we use when saving objects.
     *
     * @param string $method The save method name.
     *
     * @return $this
     */
    public function setSaveMethod($method)
    {
        $this->saveMethod = $method;

        return $this;
    }

    /**
     * Set the method we use when deleting objects.
     *
     * @param string $method The delete method name.
     *
     * @return $this
     */
    public function setDeleteMethod($method)
    {
        $this->deleteMethod = $method;

        return $this;
    }

    /**
     * Set the custom maker closure.
     *
     * @param \Closure $maker
     *
     * @return $this
     */
    public function setCustomMaker(Closure $maker)
    {
        $this->customMaker = $maker;

        return $this;
    }

    /**
     * Set the custom setter closure.
     *
     * @param \Closure $setter
     *
     * @return $this
     */
    public function setCustomSetter(Closure $setter)
    {
        $this->customSetter = $setter;

        return $this;
    }

    /**
     * Set the custom saver closure.
     *
     * @param \Closure $saver
     *
     * @return $this
     */
    public function setCustomSaver(Closure $saver)
    {
        $this->customSaver = $saver;

        return $this;
    }

    /**
     * Set the custom deleter closure.
     *
     * @param \Closure $deleter
     *
     * @return $this
     */
    public function setCustomDeleter(Closure $deleter)
    {
        $this->customDeleter = $deleter;

        return $this;
    }

    /**
     * Returns multiple versions of an object.
     *
     * These objects are generated by the create function, so are saved to the
     * database.
     *
     * @param int    $times The number of models to create.
     * @param string $model The model class name.
     * @param array  $attr  The model attributes.
     *
     * @return object[]
     */
    public function seed($times, $model, array $attr = array())
    {
        $seeds = array();
        while ($times > 0) {
            $seeds[] = $this->create($model, $attr);
            $times--;
        }

        return $seeds;
    }

    /**
     * Creates and saves in db an instance of the model.
     *
     * This object will be generated with mock attributes.
     *
     * @param string $model The model class name.
     * @param array  $attr  The model attributes.
     *
     * @return object
     */
    public function create($model, array $attr = array())
    {
        $object = $this->make($model, $attr, true);

        $this->persist($object);

        if ($this->triggerCallback($object)) {
            $this->persist($object);
        }

        return $object;
    }

    /**
     * Save the object to the database.
     *
     * @param object $object The model instance.
     *
     * @throws \League\FactoryMuffin\Exceptions\SaveFailedException
     *
     * @return void
     */
    private function persist($object)
    {
        if (!$this->save($object)) {
            if (isset($object->validationErrors) && $object->validationErrors) {
                throw new SaveFailedException(get_class($object), $object->validationErrors);
            }

            throw new SaveFailedException(get_class($object));
        }

        Arr::add($this->saved, $object);
        Arr::remove($this->pending, $object);
    }

    /**
     * Trigger the callback if we have one.
     *
     * @param object $object The model instance.
     *
     * @return bool
     */
    private function triggerCallback($object)
    {
        $model = get_class($object);

        if ($callback = Arr::get($this->callbacks, $model)) {
            $saved = $this->isPendingOrSaved($object);
            $callback($object, $saved);
            return true;
        }

        return false;
    }

    /**
     * Make an instance of the model.
     *
     * @param string $model The model class name.
     * @param array  $attr  The model attributes.
     * @param bool   $save  Are we saving, or just creating an instance?
     *
     * @return object
     */
    private function make($model, array $attr, $save)
    {
        $group = $this->getGroup($model);
        $class = $this->getModelClass($model, $group);
        $object = $this->makeClass($class);

        // Make the object as saved so that other generators persist correctly
        if ($save) {
            Arr::add($this->pending, $object);
        }

        // Get the group specific factory attributes
        if ($group) {
            $attr = array_merge($attr, $this->getFactoryAttrs($model));
        }

        // Get the factory attributes for that model
        $this->attributesFor($object, $attr);

        return $object;
    }

    /**
     * Returns the group name for this factory definition.
     *
     * @param string $model The model class name.
     *
     * @return string|null
     */
    private function getGroup($model)
    {
        if (strpos($model, ':') !== false) {
            return current(explode(':', $model));
        }
    }

    /**
     * Returns the real model class without the group prefix.
     *
     * @param string      $model The model class name.
     * @param string|null $group The model group name.
     *
     * @return string
     */
    private function getModelClass($model, $group)
    {
        if ($group) {
            return str_replace($group.':', '', $model);
        }

        return $model;
    }

    /**
     * Make an instance of the class.
     *
     * @param string $class The class name.
     *
     * @throws \League\FactoryMuffin\Exceptions\ModelNotFoundException
     *
     * @return object
     */
    private function makeClass($class)
    {
        if (!class_exists($class)) {
            throw new ModelNotFoundException($class);
        }

        if ($maker = $this->customMaker) {
            return $maker($class);
        }

        return new $class();
    }

    /**
     * Set an attribute on a model instance.
     *
     * @param object $object The model instance.
     * @param string $name   The attribute name.
     * @param mixed  $value  The attribute value.
     *
     * @return void
     */
    private function setAttribute($object, $name, $value)
    {
        if ($setter = $this->customSetter) {
            $setter($object, $name, $value);
        } else {
            $object->$name = $value;
        }
    }

    /**
     * Save our object to the db, and keep track of it.
     *
     * @param object $object The model instance.
     *
     * @throws \League\FactoryMuffin\Exceptions\SaveMethodNotFoundException
     *
     * @return mixed
     */
    private function save($object)
    {
        if ($saver = $this->customSaver) {
            return $saver($object);
        }

        if (method_exists($object, $method = $this->saveMethod)) {
            return $object->$method();
        }

        throw new SaveMethodNotFoundException($object, $method);
    }

    /**
     * Return an array of objects to be saved.
     *
     * @return object[]
     */
    public function pending()
    {
        return $this->pending;
    }

    /**
     * Is the object going to be saved?
     *
     * @param object $object The model instance.
     *
     * @return bool
     */
    public function isPending($object)
    {
        return Arr::has($this->pending, $object);
    }

    /**
     * Return an array of saved objects.
     *
     * @return object[]
     */
    public function saved()
    {
        return $this->saved;
    }

    /**
     * Is the object saved?
     *
     * @param object $object The model instance.
     *
     * @return bool
     */
    public function isSaved($object)
    {
        return Arr::has($this->saved, $object);
    }

    /**
     * Is the object saved or will be saved?
     *
     * @param object $object The model instance.
     *
     * @return bool
     */
    public function isPendingOrSaved($object)
    {
        return ($this->isSaved($object) || $this->isPending($object));
    }

    /**
     * Call the delete method on any saved objects.
     *
     * @throws \League\FactoryMuffin\Exceptions\DeletingFailedException
     *
     * return $this
     */
    public function deleteSaved()
    {
        $exceptions = array();
        foreach (array_reverse($this->saved) as $object) {
            try {
                if (!$this->delete($object)) {
                    throw new DeleteFailedException(get_class($object));
                }
            } catch (Exception $e) {
                $exceptions[] = $e;
            }

            Arr::remove($this->saved, $object);
        }

        // If we ran into problem, throw the exception now
        if ($exceptions) {
            throw new DeletingFailedException($exceptions);
        }

        return $this;
    }

    /**
     * Delete our object from the db.
     *
     * @param object $object The model instance.
     *
     * @throws \League\FactoryMuffin\Exceptions\DeleteMethodNotFoundException
     *
     * @return mixed
     */
    private function delete($object)
    {
        if ($deleter = $this->customDeleter) {
            return $deleter($object);
        }

        if (method_exists($object, $method = $this->deleteMethod)) {
            return $object->$method();
        }

        throw new DeleteMethodNotFoundException($object, $method);
    }

    /**
     * Return an instance of the model.
     *
     * This does not save it in the database. Use create for that.
     *
     * @param string $model The model class name.
     * @param array  $attr  The model attributes.
     *
     * @return object
     */
    public function instance($model, array $attr = array())
    {
        $object = $this->make($model, $attr, false);

        $this->triggerCallback($object);

        return $object;
    }

    /**
     * Returns the mock attributes for the model.
     *
     * @param object $object The model instance.
     * @param array  $attr   The model attributes.
     *
     * @return array
     */
    public function attributesFor($object, array $attr = array())
    {
        $factory_attrs = $this->getFactoryAttrs(get_class($object));
        $attributes = array_merge($factory_attrs, $attr);

        // Prepare attributes
        foreach ($attributes as $key => $kind) {
            $attr[$key] = $this->generateAttr($kind, $object);
            $this->setAttribute($object, $key, $attr[$key]);
        }

        return $attr;
    }

    /**
     * Get factory attributes.
     *
     * @param string $model The model class name.
     *
     * @throws \League\FactoryMuffin\Exceptions\NoDefinedFactoryException
     *
     * @return array
     */
    private function getFactoryAttrs($model)
    {
        if (isset($this->factories[$model])) {
            return $this->factories[$model];
        }

        throw new NoDefinedFactoryException($model);
    }

    /**
     * Define a new model factory.
     *
     * @param string        $model      The model class name.
     * @param array         $definition The attribute definitions.
     * @param \Closure|null $callback   The closure callback.
     *
     * @return $this
     */
    public function define($model, array $definition = array(), $callback = null)
    {
        $this->factories[$model] = $definition;
        $this->callbacks[$model] = $callback;

        return $this;
    }

    /**
     * Generate the attributes.
     *
     * This method will return a string, or an instance of the model.
     *
     * @param string      $kind   The kind of attribute.
     * @param object|null $object The model instance.
     *
     * @return string|object
     */
    public function generateAttr($kind, $object = null)
    {
        $kind = Generator::detect($kind, $object, $this->getFaker());

        return $kind->generate();
    }

    /**
     * Get the faker instance.
     *
     * @return \Faker\Generator
     */
    public function getFaker()
    {
        if (!$this->faker) {
            $this->faker = Faker::create($this->fakerLocale);
        }

        return $this->faker;
    }

    /**
     * Load the specified factories.
     *
     * This method expects either a single path to a directory containing php
     * files, or an array of directory paths, and will include_once every file.
     * These files should contain factory definitions for your models.
     *
     * @param string|string[] $paths The directory path(s) to load.
     *
     * @throws \League\FactoryMuffin\Exceptions\DirectoryNotFoundException
     *
     * @return $this
     */
    public function loadFactories($paths)
    {
        foreach ((array) $paths as $path) {
            if (!is_dir($path)) {
                throw new DirectoryNotFoundException($path);
            }

            $this->loadDirectory($path);
        }

        return $this;
    }

    /**
     * Load all the files in a directory.
     *
     * @param string $path The directory path to load.
     *
     * @return void
     */
    private function loadDirectory($path)
    {
        $directory = new RecursiveDirectoryIterator($path);
        $iterator = new RecursiveIteratorIterator($directory);
        $files = new RegexIterator($iterator, '/^.+\.php$/i');

        foreach ($files as $file) {
            include $file->getPathName();
        }
    }
}
