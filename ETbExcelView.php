<?php
//Yii::import('zii.widgets.grid.CGridView');

/**
 * @author Nikola Kostadinov
 * @license MIT License
 * @version 0.33
 *
 * @property IDataProvider $dataProvider
*/
class ETbExcelView extends TbGridView  //class ETbExcelView extends MyTbExtendedGridView
{
    public $i18nCategory = "EExcelView.t";
    public $behaviors=array();
    //Document properties
    public $creator;
    public $title = null;
    public $subject = 'Subject';
    public $description = '';
    public $category = '';

    /**
     * @var PHPExcel The PHPExcel object
     */
    public $objPHPExcel = null;
    public $libPath = 'application.extensions.phpexcel.Classes.PHPExcel'; //the path to the PHP excel lib

    //config
    public $autoWidth = true;
    public $exportType = self::EXPORT_TYPE_EXCEL5;
    public $disablePaging = true;
    public $filename = null; //export FileName
    public $stream = true; //stream to browser
    const GRID_MODE_GRID = 'grid';
    const GRID_MODE_EXPORT = 'export';
    public $grid_mode = self::GRID_MODE_GRID; //Whether to display grid ot export it to selected format. Possible values(grid, export)
    public $grid_mode_var='grid_mode'; //GET var for the grid mode

    //buttons config
    public $exportButtonsCSS = 'summary';
    public $exportButtons = array(self::EXPORT_TYPE_EXCEL2007);
    public $exportText;
    public $exportPageSize=null;
    public $exportMaxExecutionTime=300;

    //callbacks
    public $onRenderHeaderCell = null;
    public $onRenderDataCell = null;
    public $onRenderFooterCell = null;

    /* Page format **/
    public $fitToWidth = 0;
    public $fitToHeight = 0;
    public $portrait = true;
    public $scale = 100;

    const EXPORT_TYPE_CSV='CSV';
    const EXPORT_TYPE_PDF='PDF';
    const EXPORT_TYPE_HTML='HTML';
    const EXPORT_TYPE_EXCEL2007='Excel2007';
    const EXPORT_TYPE_EXCEL5='Excel5';
    /**
     * Mime types used for streaming
     *
     * @var array<string,array{Content-type:string,extension:string,caption:string}>
     */
    public $mimeTypes = array(
            self::EXPORT_TYPE_EXCEL5	=> array(
                    'Content-type'=>'application/vnd.ms-excel',
                    'extension'=>'xls',
                    'caption'=>'Excel(*.xls)',
            ),
            self::EXPORT_TYPE_EXCEL2007	=> array(
                    'Content-type'=>'application/vnd.ms-excel',
                    'extension'=>'xlsx',
                    'caption'=>'Excel(*.xlsx)',
            ),
            self::EXPORT_TYPE_PDF		=>array(
                    'Content-type'=>'application/pdf',
                    'extension'=>'pdf',
                    'caption'=>'PDF(*.pdf)',
            ),
            self::EXPORT_TYPE_HTML =>array(
                    'Content-type'=>'text/html',
                    'extension'=>'html',
                    'caption'=>'HTML(*.html)',
            ),
            self::EXPORT_TYPE_CSV		=>array(
                    'Content-type'=>'application/csv',
                    'extension'=>'csv',
                    'caption'=>'CSV(*.csv)',
            )
    );

    public $options = array(
            array(
                    self::EXPORT_TYPE_PDF => array(
                            'font' => array(
                                    'size' => 10,
                            ),
                    ),
            )
    );

    /**
     * Renderer to use for the PDF.
     *
     * One of:
     *     PHPExcel_Settings::PDF_RENDERER_TCPDF;
     * 	   PHPExcel_Settings::PDF_RENDERER_DOMPDF
     *     PHPExcel_Settings::PDF_RENDERER_MPDF
    */
    public $pdfRenderer;
    public $pdfRendererPath;

    public function init()
    {
        //if(!empty($this->behaviors)) {
        //}
        if($this->exportText===null) {
            $this->exportText = Yii::t($this->i18nCategory,'Export to: ');
        }
        if(!isset($this->grid_mode)) {
            if(!isset($this->grid_mode_var)) {
                $this->grid_mode_var=$this->id;
            }

            if(isset($_GET[$this->grid_mode_var])) {
                $this->grid_mode = $_GET[$this->grid_mode_var];
            } else {
                $this->grid_mode=self::GRID_MODE_GRID;
            }
        }
        if(isset($_GET['exportType'])) {
            $this->exportType = $_GET['exportType'];
            $this->grid_mode=self::GRID_MODE_EXPORT;
        }

        $lib = Yii::getPathOfAlias($this->libPath).'.php';
        if($this->isExport() and !file_exists($lib)) {
            $this->grid_mode = self::GRID_MODE_GRID;
            Yii::log("PHP Excel lib not found($lib). Export disabled !", CLogger::LEVEL_WARNING, 'EExcelview');
        }

        if(!isset($this->creator)) {
            $this->creator=Yii::app()->name;
        }

        if($this->isExport())
        {
            ini_set('max_execution_time', strval($this->exportMaxExecutionTime));

            $tz = date_default_timezone_get();
            date_default_timezone_set(Yii::app()->getLocale()->getTimezone());

            // We introduce our formatter to avoid that numbers
            // are truncated for Excel
            if(in_array($this->exportType,
                [self::EXPORT_TYPE_CSV,self::EXPORT_TYPE_EXCEL2007,self::EXPORT_TYPE_EXCEL5])
            ) {
                $this->formatter=new ETbExcelFormatter($this->formatter);
                if($this->exportPageSize!==null) {
                    $pagination=$this->dataProvider->getPagination();
                    $pagination->setPageSize($this->exportPageSize);
                }
            }

            $this->title = $this->title ? $this->title : Yii::app()->getController()->getPageTitle();
            if(strval($this->filename)==='') {
                $this->filename=$this->title;
            }
            $this->initColumns();
            //parent::init();
            //Autoload fix
            spl_autoload_unregister(array('YiiBase','autoload'));
            Yii::import($this->libPath, true);
            spl_autoload_register(array('YiiBase','autoload'));
            /* Autoload is ok now */
            if(empty($this->pdfRenderer)) {
                $this->pdfRenderer= PHPExcel_Settings::PDF_RENDERER_TCPDF;
            }
            if(empty($this->pdfRendererPath)) {
                $this->pdfRendererPath=Yii::getPathOfAlias($this->libPath). DIRECTORY_SEPARATOR . "Shared" . DIRECTORY_SEPARATOR ."PDF";
            }

            if($this->nullDisplay==='&nbsp;') {
                $this->nullDisplay=null;
            }
            if($this->blankDisplay==='&nbsp;') {
                $this->blankDisplay=null;
            }


            PHPExcel_Settings::setLocale(Yii::app()->getLocale()->getId());
            PHPExcel_Settings::setPdfRendererName($this->pdfRenderer);
            PHPExcel_Settings::setPdfRendererPath($this->pdfRendererPath);

            $this->objPHPExcel = new PHPExcel();
            // Creating a workbook
            $this->objPHPExcel->getProperties()->setCreator($this->creator);
            $this->objPHPExcel->getProperties()->setTitle($this->title);
            $this->objPHPExcel->getProperties()->setSubject($this->subject);
            $this->objPHPExcel->getProperties()->setDescription($this->description);
            $this->objPHPExcel->getProperties()->setCategory($this->category);
        } else
            parent::init();
    }

    private function setupSheet() {
        if(isset($this->options[$this->exportType])) {
            $exportOptions=$this->options[$this->exportType];
            if(isset($exportOptions['font'])) {
                $fontOptions = $exportOptions['font'];
                if(isset($fontOptions['size'])) {
                    $this->objPHPExcel->getDefaultStyle()->getFont()->setSize($fontOptions['size']);
                }
            }
            if(isset($exportOptions['margins'])) {
                $margins=$exportOptions['margins'];
                $pageMargins=$this->objPHPExcel->getActiveSheet()->getPageMargins();
                $pageMargins->setTop($margins[0]);
                $pageMargins->setRight($margins[1]);
                $pageMargins->setBottom($margins[2]);
                $pageMargins->setLeft($margins[3]);
            }
        }
        // These options only apply to the option for Excel - they do not impact the PDF.
        $pageSetup=$this->objPHPExcel->getActiveSheet()->getPageSetup();
        if($this->fitToHeight!=0 || $this->fitToWidth!=0) {
            $pageSetup->setFitToWidth($this->fitToWidth);
            $pageSetup->setFitToHeight($this->fitToHeight);
        }
        $pageSetup->setScale($this->scale);
        if(!$this->portrait) {
            $pageSetup->setOrientation(PHPExcel_Worksheet_PageSetup::ORIENTATION_LANDSCAPE);
        }
    }

    public function renderHeader()
    {
        $a=0;
        $objPHPExcel=$this->objPHPExcel;
        foreach($this->columns as $column)
        {
            $a=$a+1;
            if($column instanceof CButtonColumn) {
                $head = $column->header;
            } elseif($column->header===null && isset($column->name))
            {
                if($column->grid->dataProvider instanceof CActiveDataProvider)
                    $head = $column->grid->dataProvider->model->getAttributeLabel($column->name);
                else
                    $head = $column->name;
            } else
                $head =trim($column->header)!=='' ? $column->header : $column->grid->blankDisplay;

            $cell = $objPHPExcel->getActiveSheet()->setCellValue($this->columnName($a).'1' ,strip_tags($head), true);
            if(is_callable($this->onRenderHeaderCell))
                call_user_func_array($this->onRenderHeaderCell, array($cell, $head));
        }
        // Add filter
        //$objPHPExcel->getActiveSheet()->setAutoFilter('B1:'.$this->columnName($a).'1');
    }

    private $debug;

    public function getGrid() {
        return $this;
    }


    public function renderBody()
    {
        $pagination=$this->dataProvider->getPagination();
        if($this->disablePaging) {
            $this->enablePagination = false;
            $pagination->setCurrentPage(0);
        }

        $continue=true;
        $total=0;
        $data=$this->dataProvider->getData(true);
        $page=$pagination->getCurrentPage();
        $totalpages=$pagination->pageCount;

        //print "Page count:".$totalpages."\n";

        while($continue) {
            $n=count($data);
            if($n>0)
            {
                //$this->debug.="COUNT $n";
                for($row=0;$row<$n;++$row)
                    $this->renderRow($row,$total+$row);
            }
            $total+=$n;
            if($this->disablePaging && $page<$totalpages-1) {
                //$this->debug.="PAGE $page $totalpages<br/>";
                $page++;
                $pagination->setCurrentPage($page);
                $this->dataProvider->pagination->setCurrentPage($page);
                $data=$this->dataProvider->getData(true);
            } else {
                $continue=false;
            }
        }
        return $total;
    }

    public function renderRow($row,$offset)
    {
        $data=$this->dataProvider->data[$row];

        $a=0;
        $hasCallableRenderDataCell=is_callable($this->onRenderDataCell);
        $activeSheet=$this->objPHPExcel->getActiveSheet();
        $app=Yii::app();
		$urlBuilder=$app->getController();
		if($urlBuilder===null) {
    		$urlBuilder=$app;
		}
        foreach( $this->columns as /** @var CGridColumn $column */$column ) {
            $url=null;
            if($column instanceof CLinkColumn) {
                if($column->labelExpression !== null) {
                    $value=$column->evaluateExpression( $column->labelExpression, array(
                        'data' => $data,
                        'row' => $row
                    ) );
                } else {
                    $value=$column->label;
                }
                if($column->urlExpression !== null) {
                    $url=$column->evaluateExpression( $column->urlExpression, array(
                        'data' => $data,
                        'row' => $row
                    ) );
                }
            } elseif($column instanceof CButtonColumn) {
                $value=""; // Dont know what to do with buttons
            } else {
                $value=null;
                if($column instanceof YDataLinkColumn) {
                    $column->enableHtmlOutput=false;
                }
                if($column instanceof CDataColumn) {
                    switch($column->type) {
                        case 'shortText':
                            $value=$column->value;
                            break;
                        case 'datetimems':
                            {
                                $value=$column->value;
                                if($value !== null) {
                                    $value=$this->evaluateExpression( $value, array(
                                        'data' => $data,
                                        'row' => $row
                                    ) ) / 1000.;
                                } elseif($column->name !== null) {
                                    $value=$data->{$column->name};
                                    if($value !== null) {
                                        $value/=1000.;
                                    }
                                } elseif(is_numeric( $value )) {
                                    $value/=1000.;
                                }
                                if(is_numeric( $value )) {
                                    $value=(new DateTime())->setTimestamp( $value );
                                }
                            }
                            break;
                        case 'datetime':
                            {
                                $value=$column->value;
                                if($value !== null) {
                                    $value=$this->evaluateExpression( $value, array(
                                        'data' => $data,
                                        'row' => $row
                                    ) );
                                } elseif($column->name !== null) {
                                    $value=$data->{$column->name};
                                }

                                if(strval( $value ) !== '' && !is_numeric( $value )) {
                                    $value=Utils::convertDbDateTimeToTime( $value );
                                }
                                // if(Yii::app() instanceof CConsoleApplication) {
                                // print $column->name.":".$value.PHP_EOL;
                                // }
                                if(is_numeric( $value )) {
                                    $value=(new DateTime())->setTimestamp( $value );
                                }
                            }
                            break;
                    }
                    if($value === null) {
                        $value=$column->getDataCellContent( $row );
                    }
                    if($column instanceof YDataLinkColumn) {
                        /** @var YDataLinkColumn $column */
                        if($column->urlExpression !== null) {
                            $url=$column->evaluateExpression( $column->urlExpression, array(
                                'data' => $data,
                                'row' => $row
                            ) );
                        }
                    }
                }
            }
            /*
            } elseif(CHtml::value($column,'value')!==null) {
                $value=$this->evaluateExpression($column->value ,array('data'=>$data));
            } elseif($column->name!==null) {
                //$value=$data[$row][$column->name];
                $value= CHtml::value($data, $column->name);
                $value=$value===null ? "" : $column->grid->getFormatter()->format($value,'raw');
            }
            */

            $a++;
            $cellIndex=$this->columnName($a).(2+$offset);
            //$value.=" ".CVarDumper::dumpAsString($column->type);
            /** @var PHPExcel_Cell $cell */
/*
            if(isset($column->type)
                && !($column instanceof  CButtonColumn)
                &&(in_array($column->type,['datetimems','datetime']))
            ) {
                $cell=$activeSheet
                    ->setCellValue($cellIndex,
                            PHPExcel_Shared_Date::PHPToExcel( $value ), true);
                $cell->getStyle()->getNumberFormat()
                  //->setFormatCode('d/m/yy h:mm:ss.000');
                  ->setFormatCode('dd/mm/yyyy hh:mm:ss');
                //throw new CException(CVarDumper::dumpAsString([$value,PHPExcel_Shared_Date::PHPToExcel( $value )]));
            } else
*/
            if(isset($column->type)) {
                $cell = $this->setValueFromGridType(
                        $activeSheet,$cellIndex, $value, $column->type);
            } else {
                $strip=strip_tags($value);
                /** @var PHPExcel_Cell $cell */'@phan-var-force PHPExcel_Cell $cell';
                $cell = $activeSheet->setCellValue($cellIndex , $strip, true);
                if(strval($cell)!==strval($strip)) {
                    $cell->setValueExplicit($strip);
                }
            }

            if(is_array($url)) {
                $url=$urlBuilder->createAbsoluteUrl($url[0],array_splice($url,1));
            }
            if( is_string($url) && strlen($url)>5 && ($cell instanceof PHPExcel_Cell)) {
                    $cell->getHyperlink()->setUrl($url);
            } /*else {
                if(is_string($url)) {
                    CVarDumper::dump(['skipping',$url,$cell]);
                }
            }*/
            //$cell->setValueExplicit($strip);

            if($hasCallableRenderDataCell) {
                call_user_func_array($this->onRenderDataCell, array($cell, $data, $value));
            }
        }
        /* Add debug information by adding data to first row.
         if(!empty($this->debug)) {
        $this->objPHPExcel->getActiveSheet()->setCellValue($this->columnName(count($column)).($offset+2) ,"DEBUG ".$this->debug, true);
        $this->debug=null;
        }
        */
    }

    public function renderFooter($row)
    {
        $activeSheet=$this->objPHPExcel->getActiveSheet();
        $a=0;
        $activeSheet=$this->objPHPExcel->getActiveSheet();
        foreach($this->columns as /*$n=>*/$column)
        {
            $a=$a+1;
            if($column->footer)
            {
                $footer =trim($column->footer)!=='' ? $column->footer : $column->grid->blankDisplay;

                $cell = $activeSheet->setCellValue($this->columnName($a).($row+2) ,$footer, true);
                if(is_callable($this->onRenderFooterCell))
                    call_user_func_array($this->onRenderFooterCell, array($cell, $footer));
            }
        }

        if($row!=0&&$this->exportType===self::EXPORT_TYPE_EXCEL2007) {
            // Add filter
            $range='A1:'.$this->columnName($a).strval($row+1);
            //throw new CException($range);
            $activeSheet->setAutoFilter($range);
            //throw new CException($range);
        }
    }

    /**
     * Set PHPExcellcell value according to provided type
     *
     * @param PHPExcel_Worksheet $activeSheet
     * @param string $index
     * @param mixed $value
     * @param string $type
     * @return PHPExcel_Cell
     */
    public function setValueFromGridType($activeSheet,$index,$value,$type) {
        switch($type) {
            case 'raw':
            case 'text':
            case 'ntext':
            case 'html':
                $cell = $activeSheet->setCellValueExplicit(
                        $index , $value, PHPExcel_Cell_DataType::TYPE_STRING, true);
                break;
            case 'date':
                $cell = $activeSheet->setCellValue(
                        $index , $value, true);
                $cell->getStyle()->getNumberFormat()
                      //->setFormatCode('d/m/yy h:mm:ss.000');
                      ->setFormatCode('dd/mm/yy');
                break;
            case 'time':
                $cell = $activeSheet->setCellValue(
                        $index , $value, true);
                $cell->getStyle()->getNumberFormat()
                      //->setFormatCode('d/m/yy h:mm:ss.000');
                      ->setFormatCode('hh:mm:ss');
                break;
            case 'datetimems':
            case 'datetime':
                $cell=$activeSheet
                    ->setCellValue($index,
                            PHPExcel_Shared_Date::PHPToExcel( $value ), true);
                $cell->getStyle()->getNumberFormat()
                  //->setFormatCode('d/m/yy h:mm:ss.000');
                  ->setFormatCode('dd/mm/yyyy hh:mm:ss');
                break;
            case 'boolean':
                $cell = $activeSheet->setCellValueExplicit(
                        $index , $value, PHPExcel_Cell_DataType::TYPE_BOOL, true);
                break;
            case 'number':  // Issue: number returned is format to local format.
            case 'email':
            case 'image':
            case 'url':
            default:
                $cell = $activeSheet->setCellValue(
                        $index , $value,true);
                break;
        }
        //$activeSheet->getComment($index)->getText()->createTextRun($value);
        return $cell;
    }


    public function isExport() {
        return $this->grid_mode === self::GRID_MODE_EXPORT;
    }

    public function run()
    {
        if($this->isExport())
        {
            $this->setupSheet();

            $this->renderHeader();
            if (YII_DEBUG) {
                Utils::addFileLog();
            }
            $row = $this->renderBody();
            $this->renderFooter($row);

            $activeSheet=$this->objPHPExcel->getActiveSheet();
            //set auto width
            if($this->autoWidth) {
                //PHPExcel_Shared_Font::setAutoSizeMethod(PHPExcel_Shared_Font::AUTOSIZE_METHOD_EXACT);
                foreach(array_keys(array_keys($this->columns)) as $n/*=>$column*/) {
                    $activeSheet->getColumnDimension($this->columnName($n+1))->setAutoSize(true);
                }
            }
            $activeSheet->calculateColumnWidths();
            $a=0;
            foreach($this->columns as $column) {
                $a++;
                if(is_array($column->htmlOptions)) {
                    $dimension=$activeSheet->getColumnDimension($this->columnName($a));
                    //CVarDumper::dump($column->htmlOptions,10,true);
                    if(isset($column->htmlOptions['maxWidth'])) {
                        $maxWidth=$column->htmlOptions['maxWidth'];

                        $width=$dimension->getWidth();
                        if($width>$maxWidth) {
                            $dimension->setWidth($maxWidth);
                            $dimension->setAutoSize(false);
                        }
                    }
                    if(isset($column->htmlOptions['minWidth'])) {
                        $minWidth=$column->htmlOptions['minWidth'];
                        $width=$dimension->getWidth();
                        //print "min $minWidth $width - ";
                        if($width<$minWidth) {
                            $dimension->setWidth($minWidth);
                            $dimension->setAutoSize(false);
                        }
                    }

                }
            }

            //create writer for saving
            $objWriter = PHPExcel_IOFactory::createWriter($this->objPHPExcel, $this->exportType);
            if(!$this->filename) {
                $this->filename = $this->title;
            }
            if(!$this->stream) {
                $objWriter->save($this->filename); // Appending extension breaks inside caller .'.'.$this->mimeTypes[$this->exportType]['extension']);
            } else //output to browser
            {
                $this->startOutputHeaders();
                $objWriter->save('php://output');
                exit(0);// Yii::app()->end(); may add extra output.
            }
        } else
            parent::run();
    }

    /**
     * Clean output and write mimeheaders, etc.
     */
    public function startOutputHeaders() {
        self::cleanOutput();
        header("Cache-Control: no-cache, must-revalidate, no-store, max-age=0, private, max-stale=0, post-check=0, pre-check=0, no-transform");
        $addPublic=true;
        if(class_exists('EWebBrowser')) {
            $browser = new EWebBrowser();
            if( !($browser->getBrowser() == EWebBrowser::BROWSER_IE && $browser->getVersion() < 8 )) {
                $addPublic=false;
            }
        }
        if($addPublic) {
            header('Pragma: public'); // For < IE8
        }
        header('Content-type: '.$this->mimeTypes[$this->exportType]['Content-type']);
        header('Content-Disposition: attachment; filename="'.$this->filename.'.'.$this->mimeTypes[$this->exportType]['extension'].'"');
    }

    /**
     * Returns the coresponding excel column.(Abdul Rehman from yii forum)
     *
     * @param int $index
     * @return string
     */
    public function columnName($index)
    {
        --$index;
        if($index >= 0 && $index < 26)
            return chr(ord('A') + $index);
        else if ($index > 25)
            return ($this->columnName($index / 26)).($this->columnName($index%26 + 1));
        else
            throw new Exception("Invalid Column # ".($index + 1));
    }

    public function renderExportButtons()
    {
        $content=array();
        foreach($this->exportButtons as $key=>$button)
        {
            if(is_array($button)&&array_key_exists($key,$this->mimeTypes)) {
                $item=CMap::mergeArray($this->mimeTypes[$key], $button);
            } else if(array_key_exists($button,$this->mimeTypes)) {
                $item=$this->mimeTypes[$button];
            } else {
                continue; // Skip this button.
            }
            $type = is_array($button) ? $key : $button;
            // $url = parse_url(Yii::app()->request->requestUri);
            //$content[] = CHtml::link($item['caption'], '?'.$url['query'].'exportType='.$type.'&'.$this->grid_mode_var.'=export');
            $content[] = CHtml::link($item['caption'],
                    Yii::app()->controller->createUrl(
                            '',
                            array_merge($_GET,array('exportType'=>$type,$this->grid_mode_var=>self::GRID_MODE_EXPORT)))
            );

            /* Original:
             if (key_exists('query', $url))
                $content[] = CHtml::link($item['caption'], '?'.$url['query'].'&exportType='.$type.'&'.$this->grid_mode_var.'=export');
            else
                $content[] = CHtml::link($item['caption'], '?exportType='.$type.'&'.$this->grid_mode_var.'=export');
            */
        }
        if($content)
            echo CHtml::tag('div', array('class'=>$this->exportButtonsCSS), $this->exportText.implode(' ',$content));

    }

    /**
     * Performs cleaning on multiple levels.
     *
     * From le_top @ yiiframework.com
     *
     */
    private static function cleanOutput()
    {
        for($level=ob_get_level();$level>0;--$level)
        {
            @ob_end_clean();
        }
    }
}

class ETbExcelFormatter {

    /** @var CFormatter */
    private $formatter;
    /**
     *
     * @param CFormatter $formatter
     */
    public function __construct($formatter) {
        $this->formatter=$formatter;
    }

    public function formatNumber($value) {
        return $value;
    }

    /**
     * Do not shorten fields for Excel
     *
     * @param string $value
     * @return string
     */
    public function formatShortText($value) {
        return $value;
    }

    public function format($value,$type)
	{
	    if(!is_string($type)) {
            $method='format'.$type['type'];
	    } else {
    		$method='format'.$type;
	    }
		if(method_exists($this,$method))
			return $this->$method($value);
		else
            return $this->formatter->format($value,$type);
	}

    /**
     * Proxy implementation
     *
     * {@inheritDoc}
     * @see CComponent::__call()
     */
    public function __call($name,$parameters) {
        if(method_exists($this,'format'.$name)) {
            return call_user_func_array([$this,'format'.$name],$parameters);
        } else {
            return call_user_func_array([$this->formatter,$name],$parameters);
        }
    }
}
