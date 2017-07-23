<?php

namespace PhpBoot\Controller;

use PhpBoot\Application;
use PhpBoot\Metas\ReturnMeta;
use PhpBoot\Utils\ArrayAdaptor;
use PhpBoot\Utils\ArrayHelper;

use Symfony\Component\HttpFoundation\Response;

class ResponseHandler
{
    /**
     * 设置输出映射
     * @param $target
     * @param ReturnMeta $src
     */
    public function setMapping($target, ReturnMeta $src)
    {
        $this->mappings[$target] = $src;
    }

    /**
     * @param $target
     * @return ReturnMeta
     */
    public function eraseMapping($target)
    {
        if(!isset($this->mappings[$target])){
            return null;
        }
        $ori = $this->mappings[$target];
        unset($this->mappings[$target]);
        return $ori;
    }

    /**
     * @param $target
     * @return ReturnMeta
     */
    public function getMapping($target)
    {
        if(!array_key_exists($target, $this->mappings)){
            return null;
        }
        return $this->mappings[$target];
    }

    /**
     * @param ResponseRenderer $renderer
     * @param $return
     * @param $params
     * @return Response
     */
    public function handle(ResponseRenderer $renderer, $return, $params)
    {
        $input = [
            'return'=>$return,
            'params'=>$params
        ];

        $mappings = $this->getMappings();
        if($return instanceof Response){ //直接返回Response时, 对return不再做映射
            return $return;
        }

        $response = new Response();
        $output = [];
        foreach($mappings as $key=>$map){
            $val = \JmesPath\search($map->source, $input);
            if(substr($key, 0, strlen('response.')) == 'response.'){
                $key = substr($key, strlen('response.'));
            }
            ArrayHelper::set($output, $key, $val);
        }
        $response = new Response();
        foreach ($output as $key=>$value){
            //TODO 支持自定义格式输出
            //TODO 支持更多的输出目标
            if($key == 'content'){
                $content = $renderer->render($value);
                $response->setContent($content);
            }elseif($key == 'headers'){
                foreach ($value as $k=>$v){
                    $response->headers->set($k, $v);
                }
            }else{
                fail(new \UnexpectedValueException("Unexpected output target $key"));
            }

        }
        return $response;
    }
    /**
     * @return ReturnMeta[]
     */
    public function getMappings()
    {
        return $this->mappings;
    }
    /**
     * @var array
     */
    private $mappings;
}