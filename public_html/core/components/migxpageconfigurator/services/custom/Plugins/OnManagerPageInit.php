<?php

/**
 * Сервис для обработки события OnManagerPageInit
 */

namespace MpcServices\Plugins;


/**
 * @author Arthur Shevchenko (https://t.me/ShevArtV)
 */
class OnManagerPageInit extends PluginHandler
{
    public function run()
    {
        if(file_exists($this->corePath . 'components/sendit/')){
            $this->plugSendIt();
        }
        $this->modx->regClientStartupHTMLBlock(
            '            
            <link rel="stylesheet" href="/assets/components/sendit/css/web/index.css" type="text/css">
            <script type="module" src="/assets/components/migxpageconfigurator/js/mgr/widgets.js"></script>
            '
        );
    }

    private function plugSendIt(){
        require_once $this->corePath . 'components/sendit/services/sendit.class.php';

        $jsConfigPath = $this->modx->getOption('si_js_config_path');
        $jsPath = $this->modx->getOption('si_frontend_js');
        $jsPath = str_replace('[[+assetsUrl]]', '/assets/', $jsPath);
        $cookies = !empty($_COOKIE['SendIt']) ? json_decode($_COOKIE['SendIt'], 1) : [];

        $SendIt = new \SendIt($this->modx);
        $SendIt->getWebConfig();
        $webConfig = json_encode($SendIt->webConfig, JSON_UNESCAPED_UNICODE);

        $data = [
            'sitoken' => md5($_SERVER['REMOTE_ADDR'] . time()),
            'sitrusted' => '0',
            'sijsconfigpath' => $jsConfigPath
        ];
        \SendIt::setSession($this->modx, [
            'sitoken' => $data['sitoken'],
            'sendingLimits' => []
        ]);

        $data = array_merge($cookies, $data);
        setcookie('SendIt', json_encode($data), 0, '/');
        $this->modx->regClientStartupHTMLBlock(
            '
           <script> window.siConfig = '.$webConfig.'; </script>
            <script type="module" src="'.$jsPath.'"></script>            
            '
        );
    }
}
