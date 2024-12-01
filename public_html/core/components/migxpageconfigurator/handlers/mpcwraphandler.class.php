<?php

require_once dirname(__FILE__) . '/mpcbasehandler.class.php';

/**
 *
 */
class MpcWrapHandler extends MpcBaseHandler{
    /**
     * @param $properties
     * @return void
     */
    protected function setProperties($properties)
    {
        $this->properties = [
            'corePath' => $this->modx->getOption('core_path', null, ''),
            'extension' => $this->modx->getOption('mpc_tpl_file_extension', null, '.tpl'),
            'pdotoolsElementsPath' => $this->modx->getOption('pdotools_elements_path', null, '{core_path}elements/'),
            'pathToSections' => $this->modx->getOption('mpc_path_to_sections', null, 'sections/'),
            'cpId' => $this->modx->getOption('mpc_contacts_page_id', null, 0),
            'spId' => $this->modx->getOption('site_start', null, 1),
            'contactTvName' => $this->modx->getOption('mpc_contacts_tvname', null, 'contacts'),
            'serviceInfoTvName' => $this->modx->getOption('mpc_service_info_tvname', null, 'service_info'),
        ];
        $this->properties['pdotoolsElementsPath'] = str_replace($this->properties['corePath'], '', $this->properties['pdotoolsElementsPath']);
        $this->properties = array_merge($this->properties, $properties);
        $this->modx->addPackage('migx', $this->properties['corePath'] . 'components/migx/model/');
    }

    /**
     * @return array|object|string
     */
    public function handle(){

        $pathToSrc = $this->modx->getOption('mpc_path_to_src', null, 'elements/templates/');
        $pathToFile = $this->properties['corePath'] . $pathToSrc . $this->properties['fileName'];
        $templateHtml = file_get_contents($pathToFile);

        if($startPage = $this->modx->getObject('modResource', $this->properties['spId'])){
            $serviceInfo = $startPage->getTVValue($this->properties['serviceInfoTvName']);
            $serviceInfo = $serviceInfo ? json_decode($serviceInfo, true) : [['MIGX_id' => 1]];
            $infoItems = $this->findByAttribute($templateHtml, '[data-mpc-info]');
            if($infoItems->count()){
                foreach($infoItems as $infoItem){
                    switch($infoItem->nodeName){
                        case 'link':
                            $serviceInfo[0][$infoItem->getAttribute('data-mpc-info')] = $infoItem->getAttribute('href');
                            break;
                        case 'img':
                            $serviceInfo[0][$infoItem->getAttribute('data-mpc-info')] = $infoItem->getAttribute('src');
                            break;
                        default:
                            $serviceInfo[0][$infoItem->getAttribute('data-mpc-info')] = $infoItem->nodeValue;
                            break;
                    }
                }
            }

        }
        $contactsPage = $this->modx->getObject('modResource', $this->properties['cpId']);

        $this->modx->log(1, $templateHtml);



        $contacts = $this->findByAttribute($templateHtml, '[data-mpc-contact]');



        return $this->modx->error->success('', []);
    }
}
