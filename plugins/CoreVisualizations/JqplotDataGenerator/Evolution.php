<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package CoreVisualizations
 */
namespace Piwik\Plugins\CoreVisualizations\JqplotDataGenerator;

use Piwik\Piwik;
use Piwik\Common;
use Piwik\DataTable;
use Piwik\DataTable\Row;
use Piwik\ViewDataTable;
use Piwik\Url;
use Piwik\Plugins\CoreVisualizations\JqplotDataGenerator;

/**
 * Generates JQPlot JSON data/config for evolution graphs.
 */
class Evolution extends JqplotDataGenerator
{
    /**
     * @param DataTable|DataTable\Map $dataTable
     */
    protected function initChartObjectData($dataTable, $visualization)
    {
        // if the loaded datatable is a simple DataTable, it is most likely a plugin plotting some custom data
        // we don't expect plugin developers to return a well defined Set
        /* TODO remove completely? unecessary if DataTableCollection is created
        if ($dataTable instanceof DataTable) {
            parent::initChartObjectData($dataTable);
            return;
        }*/

        // the X label is extracted from the 'period' object in the table's metadata
        $xLabels = array();
        foreach ($dataTable->getArray() as $metadataDataTable) {
            $xLabels[] = $metadataDataTable->getMetadata('period')->getLocalizedShortString(); // eg. "Aug 2009"
        }

        $units = $this->getUnitsForColumnsToDisplay();

        // if rows to display are not specified, default to all rows (TODO: perhaps this should be done elsewhere?)
        $rowsToDisplay = $this->properties['visualization_properties']->rows_to_display
            ?: array_unique($dataTable->getColumn('label'))
            ?: array(false) // make sure that a series is plotted even if there is no data
            ;

        // collect series data to show. each row-to-display/column-to-display permutation creates a series.
        $allSeriesData = array();
        $seriesUnits = array();
        foreach ($rowsToDisplay as $rowLabel) {
            foreach ($this->properties['columns_to_display'] as $columnName) {
                $seriesLabel = $this->getSeriesLabel($rowLabel, $columnName);
                $seriesData = $this->getSeriesData($rowLabel, $columnName, $dataTable);

                $allSeriesData[$seriesLabel] = $seriesData;
                $seriesUnits[$seriesLabel] = $units[$columnName];
            }
        }

        $visualization->dataTable = $dataTable;
        $visualization->properties = $this->properties;

        $visualization->setAxisXLabels($xLabels);
        $visualization->setAxisYValues($allSeriesData);
        $visualization->setAxisYUnits($seriesUnits);

        $dataTables = $dataTable->getArray();

        if ($this->isLinkEnabled()) {
            $idSite = Common::getRequestVar('idSite', null, 'int');
            $periodLabel = reset($dataTables)->getMetadata('period')->getLabel();

            $axisXOnClick = array();
            $queryStringAsHash = $this->getQueryStringAsHash();
            foreach ($dataTable->getArray() as $idDataTable => $metadataDataTable) {
                $dateInUrl = $metadataDataTable->getMetadata('period')->getDateStart();
                $parameters = array(
                    'idSite'  => $idSite,
                    'period'  => $periodLabel,
                    'date'    => $dateInUrl->toString(),
                    'segment' => \Piwik\API\Request::getRawSegmentFromRequest()
                );
                $hash = '';
                if (!empty($queryStringAsHash)) {
                    $hash = '#' . Url::getQueryStringFromParameters($queryStringAsHash + $parameters);
                }
                $link = 'index.php?' .
                    Url::getQueryStringFromParameters(array(
                        'module' => 'CoreHome',
                        'action' => 'index',
                    ) + $parameters)
                    . $hash;
                $axisXOnClick[] = $link;
            }
            $visualization->setAxisXOnClick($axisXOnClick);
        }
    }

    private function getSeriesData($rowLabel, $columnName, $dataTable)
    {
        $seriesData = array();
        foreach ($dataTable->getArray() as $childTable) {
            // get the row for this label (use the first if $rowLabel is false)
            if ($rowLabel === false) {
                $row = $childTable->getFirstRow();
            } else {
                $row = $childTable->getRowFromLabel($rowLabel);
            }

            // get series data point. defaults to 0 if no row or no column value.
            if ($row === false) {
                $seriesData[] = 0;
            } else {
                $seriesData[] = $row->getColumn($columnName) ?: 0;
            }
        }
        return $seriesData;
    }

    /**
     * Derive the series label from the row label and the column name.
     * If the row label is set, both the label and the column name are displayed.
     * @param string $rowLabel
     * @param string $columnName
     * @return string
     */
    private function getSeriesLabel($rowLabel, $columnName)
    {
        $metricLabel = @$this->properties['translations'][$columnName];

        if ($rowLabel !== false) {
            // eg. "Yahoo! (Visits)"
            $label = "$rowLabel ($metricLabel)";
        } else {
            // eg. "Visits"
            $label = $metricLabel;
        }

        return $label;
    }

    /**
     * We link the graph dots to the same report as currently being displayed (only the date would change).
     *
     * In some cases the widget is loaded within a report that doesn't exist as such.
     * For example, the dashboards loads the 'Last visits graph' widget which can't be directly linked to.
     * Instead, the graph must link back to the dashboard.
     *
     * In other cases, like Visitors>Overview or the Goals graphs, we can link the graph clicks to the same report.
     *
     * To detect whether or not we can link to a report, we simply check if the current URL from which it was loaded
     * belongs to the menu or not. If it doesn't belong to the menu, we do not append the hash to the URL,
     * which results in loading the dashboard.
     *
     * @return array Query string array to append to the URL hash or false if the dashboard should be displayed
     */
    private function getQueryStringAsHash()
    {
        $queryString = Url::getArrayFromCurrentQueryString();
        $piwikParameters = array('idSite', 'date', 'period', 'XDEBUG_SESSION_START', 'KEY');
        foreach ($piwikParameters as $parameter) {
            unset($queryString[$parameter]);
        }
        if (\Piwik\Menu\Main::getInstance()->isUrlFound($queryString)) {
            return $queryString;
        }
        return false;
    }

    private function isLinkEnabled()
    {
        static $linkEnabled;
        if (!isset($linkEnabled)) {
            // 1) Custom Date Range always have link disabled, otherwise
            // the graph data set is way too big and fails to display
            // 2) disableLink parameter is set in the Widgetize "embed" code
            $linkEnabled = !Common::getRequestVar('disableLink', 0, 'int')
                && Common::getRequestVar('period', 'day') != 'range';
        }
        return $linkEnabled;
    }
}