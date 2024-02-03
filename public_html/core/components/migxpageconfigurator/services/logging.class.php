<?php

class Logging
{
    public function __construct(modX $modx)
    {
        $this->modx = $modx;
    }

    public function log($msg, $data = []){
        if(!empty($data)){
            $this->modx->log(1, $msg . ' ' . print_r($data,1));
        }else{
            $this->modx->log(1, $msg);
        }
    }
}