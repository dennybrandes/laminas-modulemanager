<?php

/**
 * @see       https://github.com/laminas/laminas-modulemanager for the canonical source repository
 * @copyright https://github.com/laminas/laminas-modulemanager/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-modulemanager/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\ModuleManager\Listener;

use Laminas\EventManager\EventManager;
use Laminas\EventManager\SharedEventManager;
use Laminas\ModuleManager\Listener\LocatorRegistrationListener;
use Laminas\ModuleManager\Listener\ModuleResolverListener;
use Laminas\ModuleManager\ModuleEvent;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\Application;
use Laminas\ServiceManager\ServiceManager;
use LaminasTest\ModuleManager\TestAsset\MockApplication;
use ReflectionClass;
use ReflectionProperty;

/**
 * @covers \Laminas\ModuleManager\Listener\AbstractListener
 * @covers \Laminas\ModuleManager\Listener\LocatorRegistrationListener
 */
class LocatorRegistrationListenerTest extends AbstractListenerTestCase
{
    /**
     * @var Application
     */
    protected $application;

    /**
     * @var ModuleManager
     */
    protected $moduleManager;

    /**
     * @var ServiceManager
     */
    protected $serviceManager;

    /**
     * @var SharedEventManager
     */
    protected $sharedEvents;

    protected function setUp()
    {
        $this->sharedEvents = new SharedEventManager();

        $this->moduleManager = new ModuleManager(['ListenerTestModule']);
        $this->moduleManager->setEventManager($this->createEventManager($this->sharedEvents));
        $this->moduleManager->getEventManager()->attach(
            ModuleEvent::EVENT_LOAD_MODULE_RESOLVE,
            new ModuleResolverListener,
            1000
        );

        $this->application = new MockApplication;
        $events = $this->createEventManager($this->sharedEvents);
        $events->setIdentifiers(['Laminas\Mvc\Application', 'LaminasTest\Module\TestAsset\MockApplication', 'application']);
        $this->application->setEventManager($events);

        $this->serviceManager = new ServiceManager();
        $this->serviceManager->setService('ModuleManager', $this->moduleManager);
        $this->application->setServiceManager($this->serviceManager);
    }

    public function createEventManager(SharedEventManager $sharedEvents)
    {
        $r = new ReflectionClass(EventManager::class);
        if ($r->hasMethod('setSharedManager')) {
            $events = new EventManager();
            $events->setSharedManager($sharedEvents);
            return $events;
        }

        return new EventManager($sharedEvents);
    }

    public function getRegisteredServices(ServiceManager $container)
    {
        if (method_exists($container, 'getRegisteredServices')) {
            return $container->getRegisteredServices();
        }

        $services = [];
        foreach (['aliases', 'factories', 'services'] as $type) {
            $r = new ReflectionProperty($container, $type);
            $r->setAccessible(true);
            $services[($type === 'services') ? 'instances' : $type] = array_keys($r->getValue($container));
        }

        return $services;
    }

    public function normalizeServiceNameForContainer($name, $container)
    {
        if (method_exists($container, 'configure')) {
            return $name;
        }

        return strtolower(str_replace(['_', '-', '\\', '.', ' '], '', $name));
    }

    public function testModuleClassIsRegisteredWithDiAndInjectedWithSharedInstances()
    {
        $module  = null;
        $locator = $this->serviceManager;
        $locator->setFactory('Foo\Bar', function ($s) {
            $module   = $s->get('ListenerTestModule\Module');
            $manager  = $s->get('Laminas\ModuleManager\ModuleManager');
            $instance = new \Foo\Bar($module, $manager);
            return $instance;
        });

        $locatorRegistrationListener = new LocatorRegistrationListener;
        $events = $this->moduleManager->getEventManager();
        $locatorRegistrationListener->attach($events);
        $events->attach(ModuleEvent::EVENT_LOAD_MODULE, function (ModuleEvent $e) use (&$module) {
            $module = $e->getModule();
        }, -1000);
        $this->moduleManager->loadModules();

        $this->application->bootstrap();
        $sharedInstance1 = $locator->get('ListenerTestModule\Module');
        $sharedInstance2 = $locator->get(ModuleManager::class);

        $this->assertInstanceOf('ListenerTestModule\Module', $sharedInstance1);
        $foo     = false;
        $message = '';
        try {
            $foo = $locator->get('Foo\Bar');
        } catch (\Exception $e) {
            $message = $e->getMessage();
            while ($e = $e->getPrevious()) {
                $message .= "\n" . $e->getMessage();
            }
        }
        if (! $foo) {
            $this->fail($message);
        }
        $this->assertSame($module, $foo->module);

        $this->assertInstanceOf(ModuleManager::class, $sharedInstance2);
        $this->assertSame($this->moduleManager, $locator->get('Foo\Bar')->moduleManager);
    }

    public function testNoDuplicateServicesAreDefinedForModuleManager()
    {
        $locatorRegistrationListener = new LocatorRegistrationListener;
        $events = $this->moduleManager->getEventManager();
        $locatorRegistrationListener->attach($events);

        $this->moduleManager->loadModules();
        $this->application->bootstrap();
        $container = $this->application->getServiceManager();
        $registeredServices = $this->getRegisteredServices($container);

        $aliases = $registeredServices['aliases'];
        $instances = $registeredServices['instances'];

        $this->assertContains($this->normalizeServiceNameForContainer(ModuleManager::class, $container), $aliases);
        $this->assertNotContains($this->normalizeServiceNameForContainer('ModuleManager', $container), $aliases);

        $this->assertContains($this->normalizeServiceNameForContainer('ModuleManager', $container), $instances);
        $this->assertNotContains($this->normalizeServiceNameForContainer(ModuleManager::class, $container), $instances);
    }
}
