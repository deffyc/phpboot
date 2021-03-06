<?php
namespace PhpBoot\Docgen\Swagger;

use PhpBoot\Application;
use PhpBoot\Controller\ControllerContainer;
use PhpBoot\Controller\ExceptionRenderer;
use PhpBoot\Controller\Route;
use PhpBoot\Docgen\Swagger\Schemas\ArraySchemaObject;
use PhpBoot\Docgen\Swagger\Schemas\BodyParameterObject;
use PhpBoot\Docgen\Swagger\Schemas\OperationObject;
use PhpBoot\Docgen\Swagger\Schemas\PrimitiveSchemaObject;
use PhpBoot\Docgen\Swagger\Schemas\RefSchemaObject;
use PhpBoot\Docgen\Swagger\Schemas\ResponseObject;
use PhpBoot\Docgen\Swagger\Schemas\SimpleModelSchemaObject;
use PhpBoot\Docgen\Swagger\Schemas\SwaggerObject;
use PhpBoot\Docgen\Swagger\Schemas\TagObject;
use PhpBoot\Entity\ArrayContainer;
use PhpBoot\Entity\EntityContainer;
use PhpBoot\Entity\ScalarTypeContainer;
use PhpBoot\Entity\TypeContainerInterface;
use PhpBoot\Metas\ParamMeta;
use PhpBoot\Metas\ReturnMeta;
use PhpBoot\Utils\ArrayHelper;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Swagger extends SwaggerObject
{

    /**
     * @param Application $app
     * @param ControllerContainer[] $controllers
     */
    public function appendControllers(Application $app, $controllers)
    {
        foreach ($controllers as $controller) {
            $this->appendController($app, $controller);
        }
    }

    /**
     * @param Application $app
     * @param ControllerContainer $controller
     */
    public function appendController(Application $app, ControllerContainer $controller)
    {
        //tags
        $tag = new TagObject();
        $tag->name = $controller->getSummary();
        $tag->description = $controller->getDescription();
        $this->tags[] = $tag;

        foreach ($controller->getRoutes() as $action => $route) {
            $op = new OperationObject();
            $op->tags = [$controller->getSummary()];
            $op->summary = $route->getSummary();
            $op->description = $route->getDescription();
            $op->parameters = $this->getParamsSchema($app, $controller, $action, $route);

            if ($returnSchema = $this->getReturnSchema($app, $controller, $action, $route)) {
                $op->responses['200'] = $returnSchema;
            }
            $op->responses += $this->getExceptionsSchema($app, $controller, $action, $route);

            if (!isset($this->paths[$route->getUri()])) {
                $this->paths[$route->getUri()] = [];
            }
            $method = strtolower($route->getMethod());
            $this->paths[$route->getUri()][$method] = $op;
        }
    }

    /**
     * @return string
     */
    public function toJson()
    {
        $json = $this->toArray();
        return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return self::objectToArray($this);
    }

    /**
     * @param $object
     * @return array
     */
    static public function objectToArray($object)
    {
        if (is_object($object)) {
            $object = get_object_vars($object);
        }
        $res = [];
        foreach ($object as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (is_array($v) || is_object($v)) {
                $res[$k] = self::objectToArray($v);
            } else {
                $res[$k] = $v;
            }
        }
        return $res;
    }

    /**
     * @param Application $app
     * @param ControllerContainer $controller
     * @param $action
     * @param Route $route
     * @return array
     */
    public function getExceptionsSchema(Application $app,
                                        ControllerContainer $controller,
                                        $action,
                                        Route $route)
    {
        $handler = $route->getExceptionHandler();
        if (!$handler) {
            return [];
        }
        $schemas = [];
        foreach ($handler->getExceptions() as $exception) {
            list($name, $desc) = $exception;

            $ins = $app->make($name);

            //TODO status 重复怎么办
            if ($ins instanceof HttpException) {
                $status = $ins->getStatusCode();
            } else {

                $status = 500;
            }
            if (isset($res[$status])) {
                //$this->warnings[] = "status response $status has been used for $name, $desc";
                $res = $res[$status];
            } else {
                $res = new ResponseObject();
            }
            $shortName = self::getShortClassName($name);
            $desc = "$shortName: $desc";
            $res->description = implode("\n", [$res->description, $desc]);
            $res->examples = [$shortName => $app->get(ExceptionRenderer::class)->render($ins)->getContent()];
            //$res->schema = new RefSchemaObject("#/definitions/$name");
            $schemas[$status] = $res;

        }
        return $schemas;
    }

    /**
     * @param Application $app
     * @param ControllerContainer $controller
     * @param $action
     * @param Route $route
     * @return null|ResponseObject
     */
    public function getReturnSchema(Application $app,
                                    ControllerContainer $controller,
                                    $action,
                                    Route $route)
    {
        $response = $route->getResponseHandler();
        if (!$response) {
            return null;
        }
        $mappings = $response->getMappings();
        $output = [];
        $schema = new ResponseObject();
        foreach ($mappings as $key => $map) {
            if (substr($key, 0, strlen('response.')) == 'response.') {
                $key = substr($key, strlen('response.'));
            }
            ArrayHelper::set($output, $key, $map);
        }
        //TODO 支持 header、status 等
        if (isset($output['content'])) {
            $content = $output['content'];
            if ($content instanceof ReturnMeta) {
                $schema->description = $content->description;
                $schema->schema = $this->getAnySchema($app, $controller, $action, $route, $content->container);
            } elseif (is_array($content)) {
                $tmpSchema = $this->makeTempSchema($app, $controller, $action, $route, $content);
                $schema->schema = $tmpSchema;
            }
            return $schema;
        }
        return null;
    }

    /**
     * @param Application $app
     * @param ControllerContainer $controller
     * @param $action
     * @param Route $route
     * @param array $arr
     * @return RefSchemaObject
     */
    public function makeTempSchema(Application $app,
                                   ControllerContainer $controller,
                                   $action,
                                   Route $route,
                                   array $arr)
    {
        $className = self::getShortClassName($controller->getClassName());
        $name = $className . ucfirst($action) . 'Res';

        $schema = new SimpleModelSchemaObject();

        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $schema->properties[$k] = $this->makeTempSchema($app, $controller, $action, $route, $v);
            } elseif ($v instanceof ReturnMeta) {
                $sub = $this->getAnySchema($app, $controller, $action, $route, $v->container);
                $sub->description = $v->description;
                $schema->properties[$k] = $sub;
            } elseif ($v instanceof ParamMeta) {
                if ($v->container instanceof ArrayContainer) {
                    $sub = $this->getArraySchema($app, $controller, $action, $route, $v->container);
                    //TODO array for validation
                } elseif ($v->container instanceof EntityContainer) {
                    $sub = $this->getRefSchema($app, $controller, $action, $route, $v->container);
                    //TODO array for validation
                } else {
                    $sub = new PrimitiveSchemaObject();
                    $sub->type = self::mapType($v->type);
                    self::mapValidation($v->validation, $sub);
                    unset($sub->required);
                }
                $sub->description = $v->description;
                $sub->default = $v->default;
                if (!$v->isOptional) {
                    $schema->required[] = $k;
                }
                $schema->properties[$k] = $sub;
            } else {
                //TODO how to do?
            }
        }
        $unused = $name;
        $tempId = 0;
        while (isset($this->definitions[$unused])) {
            $unused = $name . $tempId;
            $tempId++;
        }
        $this->definitions[$unused] = $schema;
        return new RefSchemaObject("#/definitions/$unused");
    }

    /**
     * @param Application $app
     * @param ControllerContainer $controller
     * @param $action
     * @param Route $route
     * @param EntityContainer $container
     * @return RefSchemaObject
     */
    public function getRefSchema(Application $app,
                                 ControllerContainer $controller,
                                 $action,
                                 Route $route,
                                 EntityContainer $container)
    {
        $name = $container->getClassName();
        if (!isset($this->definitions[$name])) {
            $this->definitions[$name] = $this->getObjectSchema($app, $controller, $action, $route, $container);
        }
        return new RefSchemaObject("#/definitions/$name");
    }

    public function getParamsSchema(Application $app,
                                    ControllerContainer $controller,
                                    $action,
                                    Route $route)
    {
        $params = $route->getRequestHandler()->getParamMetas();
        $parameters = [];
        $body = [];
        $in = 'query';
        foreach ($params as $name => $param) {
            if ($param->isPassedByReference) {
                continue;
            }
            if ($param->source == 'request.request') {
                $in = 'body';
                $name = '';
            } elseif (strpos($param->source, 'request.request.') === 0
                || $param->source == 'request.request'
            ) {
                $in = 'body';
                $name = substr($param->source, strlen('request.request.'));
            } elseif (strpos($param->source, 'request.query.') === 0) {
                $in = 'query';
                $name = substr($param->source, strlen('request.query.'));
            } elseif (strpos($param->source, 'request.cookies.') === 0) {
                $in = 'cookie';
                $name = substr($param->source, strlen('request.cookies.'));
            } elseif (strpos($param->source, 'request.headers.') === 0) {
                $in = 'header';
                $name = substr($param->source, strlen('request.headers.'));
            } elseif (strpos($param->source, 'request.files.') === 0) {
                $in = 'file';
                $name = substr($param->source, strlen('request.files.'));
            } elseif (strpos($param->source, 'request.') === 0) {
                $name = substr($param->source, strlen('request.'));
                if ($route->hasPathParam($param->name)) {
                    $in = 'path';
                } elseif ($route->getMethod() == 'POST'
                    || $route->getMethod() == 'PUT'
                    || $route->getMethod() == 'PATCH'
                ) {
                    $in = 'body';
                } else {
                    $in = 'query';
                }
            }
            if ($in != 'body') {
                if ($param->container instanceof ArrayContainer) {
                    $paramSchema = $this->getArraySchema($app, $controller, $action, $route, $param->container);
                    //TODO array for validation
                } elseif ($param->container instanceof EntityContainer) {
                    $paramSchema = $this->getRefSchema($app, $controller, $action, $route, $param->container);
                    //TODO array for validation
                } else {
                    $paramSchema = new PrimitiveSchemaObject();
                    $paramSchema->type = self::mapType($param->type);
                    self::mapValidation($param->validation, $paramSchema);
                }
                $paramSchema->in = $in;
                $paramSchema->name = $name;
                $paramSchema->description = $param->description;
                $paramSchema->default = $param->default;
                $paramSchema->required = !$param->isOptional;
                $parameters[] = $paramSchema;
            } else {
                if (!$name) {
                    $body = $param;
                } else {
                    ArrayHelper::set($body, $name, $param);
                }

            }
        }
        if ($body) {
            $paramSchema = new BodyParameterObject();
            $paramSchema->name = 'body';
            $paramSchema->in = 'body';
            if (is_array($body)) {
                $paramSchema->schema = $this->makeTempSchema($app, $controller, $action, $route, $body);
            } else {
                $paramSchema->schema = $this->getAnySchema($app, $controller, $action, $route, $body->container);
            }

            $parameters[] = $paramSchema;
        }

        return $parameters;
    }

    /**
     * @param Application $app
     * @param ControllerContainer $controller
     * @param $action
     * @param Route $route
     * @param TypeContainerInterface $container
     * @return ArraySchemaObject|PrimitiveSchemaObject|RefSchemaObject
     */
    public function getAnySchema(Application $app, ControllerContainer $controller, $action, Route $route, TypeContainerInterface $container)
    {
        if ($container instanceof EntityContainer) {
            $schema = $this->getRefSchema($app, $controller, $action, $route, $container);
        } elseif ($container instanceof ArrayContainer) {
            $schema = $this->getArraySchema($app, $controller, $action, $route, $container);
        } elseif ($container instanceof ScalarTypeContainer) {
            $schema = new PrimitiveSchemaObject();
            $schema->type = self::mapType($container->getType());
        } else {
            $schema = new PrimitiveSchemaObject();
            $schema->type = 'mixed';
        }
        return $schema;
    }

    /**
     * @param Application $app
     * @param ControllerContainer $controller
     * @param $action
     * @param Route $route
     * @param ArrayContainer $container
     * @return ArraySchemaObject
     */
    public function getArraySchema(Application $app,
                                   ControllerContainer $controller,
                                   $action,
                                   Route $route,
                                   ArrayContainer $container)
    {
        $schema = new ArraySchemaObject();
        $itemContainer = $container->getContainer();
        if ($itemContainer instanceof EntityContainer) {
            $itemSchema = $this->getRefSchema($app, $controller, $action, $route, $itemContainer);
        } elseif ($itemContainer instanceof ArrayContainer) {
            $itemSchema = $this->getArraySchema($app, $controller, $action, $route, $itemContainer);
        } elseif ($itemContainer instanceof ScalarTypeContainer) {
            $itemSchema = new PrimitiveSchemaObject();
            $itemSchema->type = self::mapType($itemContainer->getType());
        } else {
            $itemSchema = new PrimitiveSchemaObject();
            //$itemSchema->type = 'mixed';
        }
        $schema->items = $itemSchema;
        return $schema;
    }

    public function getObjectSchema(Application $app,
                                    ControllerContainer $controller,
                                    $action,
                                    Route $route,
                                    EntityContainer $container)
    {
        $schema = new SimpleModelSchemaObject();
        $schema->description = implode("\n", [$container->getSummary(), $container->getDescription()]);
        $schema->required = [];
        foreach ($container->getProperties() as $property) {
            if (!$property->isOptional) {
                $schema->required[] = $property->name;
            }
            if ($property->container instanceof EntityContainer) {
                $propertySchema = $this->getRefSchema($app, $controller, $action, $route, $property->container);
            } elseif ($property->container instanceof ArrayContainer) {
                $propertySchema = $this->getArraySchema($app, $controller, $action, $route, $property->container);
            } else {
                $propertySchema = new PrimitiveSchemaObject();
                $propertySchema->type = self::mapType($property->type);
                $propertySchema->description = implode("\n", [$property->summary, $property->description]);
                self::mapValidation($property->validation, $propertySchema);
                unset($propertySchema->required);
            }
            $schema->properties[$property->name] = $propertySchema;
        }

        return $schema;
    }

    /**
     * @param string $v
     * @param PrimitiveSchemaObject $schemaObject
     * @return PrimitiveSchemaObject
     */
    static public function mapValidation($v, PrimitiveSchemaObject $schemaObject)
    {
        if(!$v){
            return $schemaObject;
        }
        $rules = explode('|', $v);
        foreach ($rules as $r) {
            $params = explode(':', trim($r));
            $rule = $params[0];
            $params = isset($params[1]) ? explode(',', $params[1]) : [];

            if ($rule == 'required') {
                $schemaObject->required = true;
            } elseif ($rule == 'in') {
                $schemaObject->enum = $params;
            } elseif ($rule == 'lengthBetween' && isset($params[0]) && isset($params[1])) {
                $schemaObject->minLength = $params[0];
                $schemaObject->maxLength = $params[1];
            } elseif ($rule == 'lengthMin'&& isset($params[0])) {
                $schemaObject->minLength = $params[0];
            } elseif ($rule == 'lengthMax'&& isset($params[0])) {
                $schemaObject->maxLength = $params[0];
            } elseif ($rule == 'min'&& isset($params[0])) {
                $schemaObject->minimum = $params[0];
            } elseif ($rule == 'max'&& isset($params[0])) {
                $schemaObject->maximum = $params[0];
            } elseif ($rule == 'regex'&& isset($params[0])) {
                $schemaObject->pattern = $params[0];
            } elseif ($rule == 'optional') {
                $schemaObject->required = false;
            }
        }
        return $schemaObject;
    }

    /**
     * @param string $type
     * @return string
     */
    static public function mapType($type)
    {
        //TODO 如何处理 file、mixed 类型
        $map = [
            'int' => 'integer',
            'bool' => 'boolean',
        ];
        if (isset($map[$type])) {
            return $map[$type];
        }
        return $type;
    }

    /**
     * @param $className
     * @return string
     */
    static public function getShortClassName($className)
    {
        $className = explode('\\', $className);
        $className = $className[count($className) - 1];
        return $className;
    }
}