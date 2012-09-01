<?php

namespace KumbiaPHP\Kernel\Controller;

use KumbiaPHP\Di\Container\ContainerInterface;
use KumbiaPHP\Kernel\Exception\NotFoundException;
use \ReflectionClass;
use \ReflectionObject;

/**
 * Description of ControllerResolver
 *
 * @author manuel
 */
class ControllerResolver
{

    /**
     *
     * @var ContainerInterface 
     */
    protected $container;
    protected $module;
    protected $controller;
    protected $contShortName;
    protected $action;

    function __construct(ContainerInterface $con)
    {
        $this->container = $con;
    }

    public function getController()
    {
        $controllerFound = FALSE;
        $module = 'Index'; //modulo por defecto del fw, no hace falta escribirlo en la URL.
        $controller = 'Index'; //controlador por defecto si no se especifica.
        $action = 'index'; //accion por defecto si no se especifica.
        $params = array(); //parametros de la url, de existir.
        //obtenemos la url actual de la petición.
        $url = explode('/', trim($this->container->get('app.context')->getCurrentUrl(), '/'));

        //primero obtengo el modulo ó controlador.
        if (current($url)) {
            //si la url comienza con index, asumimos que estamos en el modulo
            //por defecto index, ya que este nunca debe colocarse en la URL.
            //y estaremos solicitando el controlador Index del modulo Index
            if ($this->camelcase(current($url)) !== 'Index') {
                //verifico la existencia del patron de la url en los modulos
                if (array_key_exists($this->camelcase(current($url)), $this->container
                                        ->get('app.context')->getModules())) {
                    //si concuerda con un algun indice, es un modulo.
                    $module = $this->camelcase(current($url));
                    next($url);

                    //si no es un modulo, verificamos que sea un controlador.
                } elseif (!$this->isController($this->camelcase($module), current($url))) {
                    //si no es ni modulo ni controller, lanzo la excepcion.
                    throw new NotFoundException(sprintf("No existe el Módulo \"%s\"", current($url)), 404);
                }
            }
        }
        //ahora obtengo el controlador
        if (current($url)) {
            //si no es un controlador lanzo la excepcion
            if (!$this->isController($module, current($url))) {
                throw new NotFoundException(sprintf("El controlador \"%sController\" para el Módulo \"%s\" no Existe", $controller, $module), 404);
            }
            $controller = $this->camelcase(current($url));
            next($url);
        }
        //luego obtenemos la acción
        if (current($url)) {
            $action = $this->camelcase(current($url), TRUE);
            next($url);
        }
        //por ultimo los parametros
        if (current($url)) {
            $params = array_slice($url, key($url));
        }

        $this->module = $module;
        $this->contShortName = $controller;
        $this->action = $action;

        $app = $this->container->get('app.context');
        $app->setCurrentModule($module);
        $app->setCurrentController($controller);

        return $this->createController($module, $controller, $action, $params);
    }

    /**
     * Convierte la cadena con espacios o guión bajo en notacion camelcase
     *
     * @param string $s cadena a convertir
     * @param boolean $firstLower indica si es lower camelcase
     * @return string
     * */
    protected function camelcase($string, $firstLower = FALSE)
    {
        $string = str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($string))));
        if ($firstLower) {
            // Notacion lowerCamelCase
            $string[0] = strtolower($string[0]);
            return $string;
        } else {
            return $string;
        }
    }

    protected function isController($module, $controller)
    {
        $path = $this->container->get('app.context')->getModules($module);
        return is_file("{$path}/{$module}/Controller/{$this->camelcase($controller)}Controller.php");
    }

    protected function createController($module, $controller, $action, $params)
    {
        //creo el namespace para poder crear la instancia del controlador
        $path = $module . '/';
        //creo el nombre del controlador con el sufijo Controller
        $controllerName = $controller . 'Controller';
        //uno el namespace y el nombre del controlador.
        $controllerClass = str_replace('/', '\\', $path . 'Controller/') . $controllerName;

        try {
            $reflectionClass = new ReflectionClass($controllerClass);
        } catch (\Exception $e) {
            throw new NotFoundException(sprintf("No exite el controlador \"%s\" en el Módulo \"%s\"", $controllerName, $module), 404);
        }

        //verifico si la clase hereda de Controller
        if ($reflectionClass->isSubclassOf('\\KumbiaPHP\\Kernel\\Controller\\Controller')) {
            //si es así le paso el contenedor como argumento
            $this->controller = $reflectionClass->newInstanceArgs(array($this->container));
            $this->setViewDefault($action);
        } else {
            //si no es una instancia de Controller, lo creo como una simple clase PHP
            $this->controller = $reflectionClass->newInstance();
        }

        return array($this->controller, $action, $params);
    }

    public function executeAction($action, $arguments)
    {
        $this->action = $action;
        
        $controller = new ReflectionObject($this->controller);

        $this->executeBeforeFilter($controller);

        $this->validateAction($controller, $arguments);
        
        $response = call_user_func_array(array($this->controller, $this->action), $arguments);

        $this->executeAfterFilter($controller);

        return $response;
    }

    public function getPublicProperties()
    {
        return get_object_vars($this->controller);
    }

    public function getView()
    {
        return $this->getParamValue('view');
    }

    public function getTemplate()
    {
        return $this->getParamValue('template');
    }

    public function getModulePath()
    {
        $namespaces = $this->container->get('app.context')->getModules();
        return rtrim($namespaces[$this->module] . '/') . '/' . $this->module;
    }

    protected function validateAction(\ReflectionObject $controller, array $params)
    {
        if ($controller->hasProperty('limitParams')) {
            $limitParams = $controller->getProperty('limitParams');
            $limitParams->setAccessible(true);
            $limitParams = $limitParams->getValue($this->controller);
        } else {
            $limitParams = TRUE; //por defeto siempre limita los parametro
        }

//        if ($controller->hasProperty('parameters')) {
//            $parameters = $controller->getProperty('parameters');
//            $parameters->setAccessible(true);
//            $parameters->setValue($this->controller, $params);
//        }
        //verificamos la existencia del metodo.
        if (!$controller->hasMethod($this->action)) {
            throw new NotFoundException(sprintf("No exite el metodo \"%s\" en el controlador \"%sController\"", $this->action, $this->contShortName), 404);
        }

        $reflectionMethod = $controller->getMethod($this->action);

        //verificamos que no sea el constructor a quien se llama
        if ($reflectionMethod->isConstructor()) {
            throw new NotFoundException(sprintf("Se está intentando ejecutar el constructor del controlador como una acción, en el controlador \"%sController\"", $this->contShortName), 404);
        }

        if (in_array($this->action, array('beforeFilter', 'afterFilter'))) {
            throw new NotFoundException(sprintf("Se está intentando ejecutar el filtro \"%s\" del controlador \"%sController\"", $this->action, $this->contShortName), 404);
        }

        //el nombre del metodo debe ser exactamente igual al camelCase
        //de la porcion de url
        if ($reflectionMethod->getName() !== $this->action) {
            throw new NotFoundException(sprintf("No exite el metodo <b>%s</b> en el controlador \"%sController\"", $this->action, $this->contShortName), 404);
        }

        //se verifica que el metodo sea public
        if (!$reflectionMethod->isPublic()) {
            throw new NotFoundException(sprintf("Éstas Tratando de acceder a un metodo no publico \"%s\" en el controlador \"%sController\"", $this->action, $this->contShortName), 404);
        }

        //verificamos si el primer parametro del metodo requiere una
        //instancia de Request
        $parameters = $reflectionMethod->getParameters();
        //si espera parametros y es un objeto lo que espera
        if (count($parameters) && $parameters[0]->getClass()) {
            //le pasamos el Request actual
            array_unshift($params, $this->container->get('request'));
        }
        /**
         * Verificamos que los parametros coincidan 
         */
        if ($limitParams && (count($params) < $reflectionMethod->getNumberOfRequiredParameters() ||
                count($params) > $reflectionMethod->getNumberOfParameters())) {

            throw new NotFoundException(sprintf("Número de parámetros erróneo para ejecutar la acción \"%s\" en el controlador \"%sController\"", $action, $this->contShortName), 404);
        }
    }

    protected function executeBeforeFilter(ReflectionObject $controller)
    {
        if ($controller->hasMethod('beforeFilter')) {
            $method = $controller->getMethod('beforeFilter');
            $method->setAccessible(TRUE);
            //verificamos si el primer parametro del beforeFilter requiere una
            //instancia de Request
            $parameters = $method->getParameters();
            //si espera parametros y es un objeto lo que espera
            if (count($parameters) && $parameters[0]->getClass()) {
                //le pasamos el Request actual
                $request = $this->container->get('request');
            } else {
                $request = NULL;
            }
            if (NULL !== $result = $method->invoke($this->controller, $request)) {
                if (!is_string($result)) {
                    throw new NotFoundException(sprintf("El método \"beforeFilter\" solo puede devolver una cadena, en el Controlador \"%sController\"", $this->contShortName));
                }
                if (!$controller->hasMethod($result)) {
                    throw new NotFoundException(sprintf("El método \"beforeFilter\" está devolviendo el nombre de una acción inexistente \"%s\" en el Controlador \"%sController\"", $result, $this->contShortName));
                }
                //si el beforeFilter del controlador devuelve un valor, el mismo será
                //usado como el nuevo nombre de la acción a ejecutar.
                $this->action = $result;
            }
        }
    }

    protected function executeAfterFilter(ReflectionObject $controller)
    {
        if ($controller->hasMethod('afterFilter')) {
            $method = $controller->getMethod('afterFilter');
            $method->setAccessible(TRUE);
            $method->invoke($this->controller);
        }
    }

    protected function getParamValue($propertie)
    {
        $reflection = new \ReflectionClass($this->controller);

        //obtengo el parametro del controlador.
        $propertie = $reflection->getProperty($propertie);

        //lo hago accesible para poderlo leer
        $propertie->setAccessible(true);

        //y retorno su valor
        return $propertie->getValue($this->controller);
    }

    protected function setViewDefault($action)
    {
        $reflection = new \ReflectionClass($this->controller);

        //obtengo el parametro del controlador.
        $propertie = $reflection->getProperty('view');

        //lo hago accesible para poderlo leer
        $propertie->setAccessible(true);
        $propertie->setValue($this->controller, $action);
    }

}