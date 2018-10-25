<?php

class PdfBookExportRequestHooks
{

    public static function onRegistration()
    {
        global $wgLogTypes, $wgLogNames, $wgLogHeaders, $wgLogActions;
        $wgLogTypes[] = 'pdf';
        $wgLogNames['pdf'] = 'pdflogpage';
        $wgLogHeaders['pdf'] = 'pdflogpagetext';
        $wgLogActions['pdf/book'] = 'pdflogentry';
    }

    /**
     * Parser function to insert a link to pdf.
     */
    public static function parserInit($parser)
    {
        $parser->setFunctionHook('pdfBookExportButton', array(
            'PdfBookExportRequestHooks',
            'addButtonParser'
        ));
        return true;
    }

    public static function addButtonParser($input, $type = 'top', $number = 4)
    {
        $title = $input->getTitle();
        $out = '<li id="ca-pdfexport"><a href="/w/index.php/' . $title->getText() . '?action=pdfbookexport&amp;format=single">Export PDF</a></li>';
        return array(
            $out,
            'noparse' => true,
            'isHTML' => true
        );
    }

    /**
     * Perform the export operation
     */
    public static function onUnknownAction($action, $article)
    {
        global $wgOut, $wgUser, $wgRequest, $wgPdfBookExportRequestDownload;
        global $wgUploadDirectory, $wgPdfBookExportErrorLog;

        if ($action == 'pdfbookexport') {
            $title = $article->getTitle();
            echo $title;

            function get_data($url)
            {
                $ch = curl_init();
                $timeout = 5;
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_VERBOSE, true);
                $data = curl_exec($ch);
                if (curl_errno($ch)) {
                    echo 'Request Error:' . curl_error($ch);
                }
                curl_close($ch);
                return $data;
            }

            function get_group_pages($title)
            {
                $list = "";
                $pageTitle = \Title::newFromText('Book:' . $title->getBaseText());
                $wikiPage = new WikiPage($pageTitle);
                $revision = $wikiPage->getRevision();
                $content = $revision->getContent(Revision::RAW);
                $text = ContentHandler::getContentText($content);
                $text = ltrim($text, '/\**/');
                $arr = preg_split("/\n\**/", $text);

                return ($arr);
            }

            $filename = 'Wikifab-' . str_replace(" ", "", $title->getBaseText());

            // Auto Domains
             $domain = $_SERVER['HTTP_HOST'];
 //           $domain = "dokit";
            $prefix = 'http://';

            $arr = get_group_pages($title);
            $book = 'images/books/book.php';
            $handle = fopen($book, 'w') or die('Cannot open file:  ' . $book);
            $file = "";
            for ($i = 0; $i < $arrSize = count($arr); $i ++) {
                $titletmp = Title::newFromText(substr($arr[$i], 1));
                if ($titletmp->exists()) {
                    $tmpfile = get_data($prefix . $domain . "/?title=" . substr($arr[$i], 1));
                } else {
                    $tmpfile = get_data($prefix . $domain . "/w/index.php/Special:TitlePdf?group=" . $title->getBaseText() . "&titre=" . $arr[$i]);
                }
                $tmpfile = preg_replace("/<nav(.*)nav>/ms", "", $tmpfile);
                $tmpfile = preg_replace('/<!DOCTYPE html>(.*)<!-- main content header __!>/ms', "", $tmpfile);
                $tmpfile = preg_replace('/<div class="footerdata">(.*)html>/ms', "", $tmpfile);
                $tmpfile = preg_replace('/<h2 class="cs-title(.*)<!--/ms', "<!--", $tmpfile);
                $file .= '<div class="print-only printPageBreakBefore"></div>' . $tmpfile . '<hr class="print-only printPageBreakAfter">';
            }
            fwrite($handle, $file);

            $options = self::getOptions();

            $exportDir = $wgUploadDirectory . '/pdfbookexport';
            if (! file_exists($exportDir)) {
                mkdir($exportDir, 0777, true);
            }
            $cacheFile = $exportDir . '/cache-' . md5($title->getFullURL());

            // check cache File
            $needReload = true;
            if (file_exists($cacheFile)) {
                // last edit timestamp :
                $timestamp = $article->getTimestamp();
                // cacheFile timestamp :
                $fileTimeStamp = date("YmdHis", filemtime($cacheFile));
                $needReload = $timestamp > $fileTimeStamp;
            }

            echo $title->getFullURL();
            // generate file if cache file not valid
            if (! file_exists($cacheFile) || $needReload) {
                echo "file don't exist!";
                $convertResult = self::convertToPdfWithWkhtmltopdf($prefix. $domain."/w/images/books/book.php", $cacheFile, $options);
            }

            if (file_exists($cacheFile)) {
                echo "file exist!";
                $wgOut->disable();
                header("Content-Type: application/pdf");
                if ($wgPdfBookExportRequestDownload) {
                    header("Content-Disposition: attachment; filename=\"$filename.pdf\"");
                } else {
                    header("Content-Disposition: inline; filename=\"$filename.pdf\"");
                }
                readfile($cacheFile);
            } else if ($wgPdfBookExportErrorLog) {
                wfErrorLog("Error in PDF generation for $filename", $wgPdfBookExportErrorLog);
                wfErrorLog("Error cmd " . $convertResult['cmd'], $wgPdfBookExportErrorLog);
                wfErrorLog("Error result " . $convertResult['output'], $wgPdfBookExportErrorLog);
            }
        }
    }

    static function getOptions()
    {
        global $wgPdfBookExportRequestWkhtmltopdfParams;
        global $wgPdfBookExportRequestWkhtmltopdfReplaceHostname;
        global $wgPdfBookExportRequestHeaderFile, $wgPdfBookExportRequestFooterFile;

        if ($wgPdfBookExportRequestFooterFile == 'default') {
            $wgPdfBookExportRequestFooterFile = __DIR__ . '/templates/footer.html';
        }
        $opt = [
            'left' => 10,
            'right' => 10,
            'top' => 10,
            'bottom' => 10
        ];
        if ($wgPdfBookExportRequestWkhtmltopdfReplaceHostname) {
            $opt['replaceHostname'] = $wgPdfBookExportRequestWkhtmltopdfReplaceHostname;
        }
        if ($wgPdfBookExportRequestWkhtmltopdfParams) {
            $opt['customsparams'] = $wgPdfBookExportRequestWkhtmltopdfParams;
        }
        if ($wgPdfBookExportRequestHeaderFile) {
            $opt['header-html'] = $wgPdfBookExportRequestHeaderFile;
        }
        if ($wgPdfBookExportRequestFooterFile) {
            $opt['footer-html'] = $wgPdfBookExportRequestFooterFile;
        }
        return $opt;
    }

    private static function convertToPdfWithWkhtmltopdf($htmlFile, $outputFile, $options)
    {
        global $wgServer;

        // call wkhtmltopdf with url of the page (will do https requests to get the page)
        $cmd = "-L {$options['left']} -R {$options['right']} -T {$options['top']} -B {$options['bottom']} --print-media-type ";

        // this do not work with current version of wkhtmltopdf
        // $cmd = "$cmd --footer-right \"Page [page] / [toPage]\"";
        // $headerFile = dirname(__FILE__) . '/templates/header.html';
        // $footerFile = dirname(__FILE__) . '/templates/footer.html';
        // $cmd = "$cmd --header-html \"$headerFile\" ";
        // $cmd = "$cmd --footer-html \"$footerFile\" ";

        if (isset($options['customsparams'])) {
            $cmd = "$cmd {$options['customsparams']}";
        }
        if (isset($options['header-html'])) {
            $cmd = "$cmd --header-html {$options['header-html']}";
        }
        if (isset($options['footer-html'])) {
            $cmd = "$cmd --footer-html {$options['footer-html']}";
        }
        if (isset($options['replaceHostname'])) {
            $htmlFile = str_replace($wgServer, $options['replaceHostname'], $htmlFile);
            echo $htmlfile;
        }
        // Build the htmldoc command
        $cmd = "xvfb-run /usr/bin/wkhtmltopdf --javascript-delay 2000 $cmd \"$htmlFile\" \"$outputFile\"";

        // Execute the command outputting to the cache file
        exec("$cmd", $output, $result);
        return array(
            'cmd' => $cmd,
            'output' => $output,
            'result' => $result
        );
    }

    /**
     * Return a sanitised property for htmldoc using global, request or passed default
     */
    private static function setProperty($name, $val, $prefix = 'pdf')
    {
        global $wgRequest;
        if ($wgRequest->getText("$prefix$name"))
            $val = $wgRequest->getText("$prefix$name");
        if ($wgRequest->getText("amp;$prefix$name"))
            $val = $wgRequest->getText("amp;$prefix$name"); // hack to handle ampersand entities in URL
        if (isset($GLOBALS["wgPdfBook$name"]))
            $val = $GLOBALS["wgPdfBook$name"];
        return preg_replace('|[^-_:.a-z0-9]|i', '', $val);
    }

    /**
     * Add PDF to actions tabs in MonoBook based skins
     */
    public static function onSkinTemplateTabs($skin, &$actions)
    {
        global $wgPdfBookExportRequestTab;

        $ns = $skin->getTitle()->getNamespace();
        if ($ns != NS_GROUP) {
            return true;
        }

        if ($wgPdfBookExportRequestTab) {
            $actions['views']['pdfbookexport'] = array(
                'class' => false,
                'text' => wfMessage('pdfbookexportrequest-print')->text(),
                'href' => self::actionLink($skin)
            );
        }
        return true;
    }

    /**
     * Add PDF to actions tabs in vector based skins
     */
    public static function onSkinTemplateNavigation($skin, &$actions)
    {
        global $wgPdfBookExportRequestTab;

        $ns = $skin->getTitle()->getNamespace();
        if ($ns != NS_GROUP) {
            return true;
        }

        if ($wgPdfBookExportRequestTab) {
            $actions['views']['pdfexport'] = array(
                'class' => false,
                'text' => wfMessage('pdfbookexportrequest-print')->text(),
                'href' => self::actionLink($skin)
            );
        }
        return true;
    }

    /**
     * Get the URL for the action link
     */
    public static function actionLink($skin)
    {
        $qs = 'action=pdfbookexport&format=single';
        // removed to avoir Notice: Array to string conversion in /var/www/preprod.wikifab.org/extensions/PdfExportRequest/PdfExportRequest.hooks.php on line 190
        // Be should be added back to be able to print pdf of older versions
        // foreach( $_REQUEST as $k => $v ) if( $k != 'title' ) $qs .= "&$k=$v";
        return $skin->getTitle()->getLocalURL($qs);
    }
}
