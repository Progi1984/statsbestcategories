<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class statsbestcategories extends ModuleGrid
{
    private $html;
    private $query;
    private $columns;
    private $default_sort_column;
    private $default_sort_direction;
    private $empty_message;
    private $paging_message;

    public function __construct()
    {
        $this->name = 'statsbestcategories';
        $this->tab = 'administration';
        $this->version = '2.0.1';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;

        parent::__construct();

        $this->default_sort_column = 'totalPriceSold';
        $this->default_sort_direction = 'DESC';
        $this->empty_message = $this->trans('Empty recordset returned', array(), 'Modules.Statsbestcategories.Admin');
        $this->paging_message = $this->trans('Displaying %1$s of %2$s', array('{0} - {1}', '{2}'), 'Admin.Global');

        $this->columns = array(
            array(
                'id' => 'name',
                'header' => $this->trans('Name', array(), 'Admin.Global'),
                'dataIndex' => 'name',
                'align' => 'left'
            ),
            array(
                'id' => 'totalQuantitySold',
                'header' => $this->trans('Total Quantity Sold', array(), 'Modules.Statsbestcategories.Admin'),
                'dataIndex' => 'totalQuantitySold',
                'align' => 'center'
            ),
            array(
                'id' => 'totalPriceSold',
                'header' => $this->trans('Total Price', array(), 'Modules.Statsbestcategories.Admin'),
                'dataIndex' => 'totalPriceSold',
                'align' => 'right'
            ),
            array(
                'id' => 'totalWholeSalePriceSold',
                'header' => $this->trans('Total Margin', array(), 'Modules.Statsbestcategories.Admin'),
                'dataIndex' => 'totalWholeSalePriceSold',
                'align' => 'center'
            ),
            array(
                'id' => 'totalPageViewed',
                'header' => $this->trans('Total Viewed', array(), 'Modules.Statsbestcategories.Admin'),
                'dataIndex' => 'totalPageViewed',
                'align' => 'center'
            )
        );

        $this->displayName = $this->trans('Best categories', array(), 'Modules.Statsbestcategories.Admin');
        $this->description = $this->trans('Enrich your stats, add a list of the best selling categories to the dashboard.', array(), 'Modules.Statsbestcategories.Admin');
        $this->ps_versions_compliancy = array('min' => '1.7.6.0', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        return (parent::install() && $this->registerHook('displayAdminStatsModules'));
    }

    public function hookDisplayAdminStatsModules($params)
    {
        $onlyChildren = (int)Tools::getValue('onlyChildren');

        $engine_params = array(
            'id' => 'id_category',
            'title' => $this->displayName,
            'columns' => $this->columns,
            'defaultSortColumn' => $this->default_sort_column,
            'defaultSortDirection' => $this->default_sort_direction,
            'emptyMessage' => $this->empty_message,
            'pagingMessage' => $this->paging_message,
            'customParams' => array(
                'onlyChildren' => $onlyChildren,
            )
        );

        if (Tools::getValue('export')) {
            $this->csvExport($engine_params);
        }

        $this->html = '
            <div class="panel-heading">
                <i class="icon-sitemap"></i> '.$this->displayName.'
            </div>
            '.$this->engine($engine_params).'
            <div class="row form-horizontal">
                <div class="col-md-3">
                    <a class="btn btn-default export-csv" href="'.Tools::safeOutput($_SERVER['REQUEST_URI'].'&export=1').'">
                        <i class="icon-cloud-upload"></i> '.$this->trans('CSV Export', array(), 'Admin.Global').'
                    </a>
                </div>
                <div class="col-md-9">
                    <div class="checkbox">
                        <label for="onlyChildren">
                            <input type="checkbox" name="onlyChildren" id="onlyChildren" value="1" '.($onlyChildren == 1 ? 'checked="checked"' : '').'>
                            '.$this->trans('Display final level categories only (that have no child categories)', array(), 'Modules.Statsbestcategories.Admin').'
                        </label>
                    </div>

                </div>
            </div>
            <script type="text/javascript">
                $(function(){
                    $("#onlyChildren").change(function(){
                        $("#calendar_form").append($(this).clone().css("display", "none")).submit();
                    });
                });
            </script>';

        return $this->html;
    }

    public function getData()
    {
        $currency = new Currency(Configuration::get('PS_CURRENCY_DEFAULT'));
        $date_between = $this->getDate();
        $id_lang = $this->getLang();

        //If column 'order_detail.original_wholesale_price' does not exist, create it
        Db::getInstance(_PS_USE_SQL_SLAVE_)->query('SHOW COLUMNS FROM `'._DB_PREFIX_.'order_detail` LIKE "original_wholesale_price"');
        if (Db::getInstance()->NumRows() == 0) {
            Db::getInstance()->execute('ALTER TABLE `'._DB_PREFIX_.'order_detail` ADD `original_wholesale_price` DECIMAL( 20, 6 ) NOT NULL DEFAULT  "0.000000"');
        }

        // If a shop is selected, get all children categories for the shop
        $categories = array();
        if (Shop::getContext() != Shop::CONTEXT_ALL) {
            $sql = 'SELECT c.nleft, c.nright
                    FROM '._DB_PREFIX_.'category c
                    WHERE c.id_category IN (
                        SELECT s.id_category
                        FROM '._DB_PREFIX_.'shop s
                        WHERE s.id_shop IN ('.implode(', ', Shop::getContextListShopID()).')
                    )';
            if ($result = Db::getInstance()->executeS($sql)) {
                $ntree_restriction = array();
                foreach ($result as $row) {
                    $ntree_restriction[] = '(nleft >= '.$row['nleft'].' AND nright <= '.$row['nright'].')';
                }

                if ($ntree_restriction) {
                    $sql = 'SELECT id_category
                            FROM '._DB_PREFIX_.'category
                            WHERE '.implode(' OR ', $ntree_restriction);
                    if ($result = Db::getInstance()->executeS($sql)) {
                        foreach ($result as $row) {
                            $categories[] = $row['id_category'];
                        }
                    }
                }
            }
        }

        $onlyChildren = '';
        if ((int)Tools::getValue('onlyChildren') == 1) {
            $onlyChildren = 'AND NOT EXISTS (SELECT NULL FROM '._DB_PREFIX_.'category WHERE id_parent = ca.id_category)';
        }

        // Get best categories

        $this->query = '
            SELECT SQL_CALC_FOUND_ROWS ca.`id_category`, CONCAT(parent.name, \' > \', calang.`name`) as name,
            IFNULL(SUM(t.`totalQuantitySold`), 0) AS totalQuantitySold,
            ROUND(IFNULL(SUM(t.`totalPriceSold`), 0), 2) AS totalPriceSold,
            ROUND(IFNULL(SUM(t.`totalWholeSalePriceSold`), 0), 2) AS totalWholeSalePriceSold,
            (
                SELECT IFNULL(SUM(pv.`counter`), 0)
                FROM `'._DB_PREFIX_.'page` p
                LEFT JOIN `'._DB_PREFIX_.'page_viewed` pv ON p.`id_page` = pv.`id_page`
                LEFT JOIN `'._DB_PREFIX_.'date_range` dr ON pv.`id_date_range` = dr.`id_date_range`
                LEFT JOIN `'._DB_PREFIX_.'product` pr ON CAST(p.`id_object` AS UNSIGNED INTEGER) = pr.`id_product`
                LEFT JOIN `'._DB_PREFIX_.'category_product` capr2 ON capr2.`id_product` = pr.`id_product`
                WHERE capr.`id_category` = capr2.`id_category`
                AND p.`id_page_type` = 1
                AND dr.`time_start` BETWEEN '.$date_between.'
                AND dr.`time_end` BETWEEN '.$date_between.'
            ) AS totalPageViewed,
            (
                SELECT COUNT(id_category) FROM '._DB_PREFIX_.'category WHERE `id_parent` = ca.`id_category`
            ) AS hasChildren
        FROM `'._DB_PREFIX_.'category` ca
        LEFT JOIN `'._DB_PREFIX_.'category_lang` calang ON (ca.`id_category` = calang.`id_category` AND calang.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('calang').')
        LEFT JOIN `'._DB_PREFIX_.'category_lang` parent ON (ca.`id_parent` = parent.`id_category` AND parent.`id_lang` = '.(int)$id_lang.Shop::addSqlRestrictionOnLang('parent').')
        LEFT JOIN `'._DB_PREFIX_.'category_product` capr ON ca.`id_category` = capr.`id_category`
        LEFT JOIN (
            SELECT pr.`id_product`, t.`totalQuantitySold`, t.`totalPriceSold`, t.`totalWholeSalePriceSold`
            FROM `'._DB_PREFIX_.'product` pr
            LEFT JOIN (
                SELECT pr.`id_product`, pa.`wholesale_price`,
                    IFNULL(SUM(cp.`product_quantity`), 0) AS totalQuantitySold,
                    IFNULL(SUM(cp.`unit_price_tax_excl` * cp.`product_quantity`), 0) / o.conversion_rate AS totalPriceSold,
                    IFNULL(SUM(
                        CASE
                            WHEN cp.`original_wholesale_price` <> "0.000000"
                            THEN cp.`original_wholesale_price` * cp.`product_quantity`
                            WHEN pa.`wholesale_price` <> "0.000000"
                            THEN pa.`wholesale_price` * cp.`product_quantity`
                            WHEN pr.`wholesale_price` <> "0.000000"
                            THEN pr.`wholesale_price` * cp.`product_quantity`
                        END
                    ), 0) / o.conversion_rate AS totalWholeSalePriceSold
                FROM `'._DB_PREFIX_.'product` pr
                LEFT OUTER JOIN `'._DB_PREFIX_.'order_detail` cp ON pr.`id_product` = cp.`product_id`
                LEFT JOIN `'._DB_PREFIX_.'orders` o ON o.`id_order` = cp.`id_order`
                LEFT JOIN `'._DB_PREFIX_.'product_attribute` pa ON pa.`id_product_attribute` = cp.`product_attribute_id`
                '.Shop::addSqlRestriction(Shop::SHARE_ORDER, 'o').'
                WHERE o.valid = 1
                AND o.invoice_date BETWEEN '.$date_between.'
                GROUP BY pr.`id_product`
            ) t ON t.`id_product` = pr.`id_product`
        ) t    ON t.`id_product` = capr.`id_product`
        '.(($categories) ? 'WHERE ca.id_category IN ('.implode(', ', $categories).')' : '').'
        '.$onlyChildren.'
        GROUP BY ca.`id_category`
        HAVING ca.`id_category` != 1';


        if (Validate::IsName($this->_sort)) {
            $this->query .= ' ORDER BY `'.bqSQL($this->_sort).'`';
            if (isset($this->_direction) && Validate::isSortDirection($this->_direction)) {
                $this->query .= ' '.$this->_direction;
            }
        }

        if (($this->_start === 0 || Validate::IsUnsignedInt($this->_start)) && Validate::IsUnsignedInt($this->_limit)) {
            $this->query .= ' LIMIT '.(int)$this->_start.', '.(int)$this->_limit;
        }

        $values = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($this->query);
        foreach ($values as &$value) {
            if ((int)Tools::getIsset('export') == false) {
                $parts = explode('>', $value['name']);
                $value['name'] = '<i class="icon-folder-open"></i> '.trim($parts[0]).' > ';
                if ((int)$value['hasChildren'] == 0) {
                    $value['name'] .= '&bull; ';
                } else {
                    $value['name'] .= '<i class="icon-folder-open"></i> ';
                }
                $value['name'] .= trim($parts[1]);
            }

            if (isset($value['totalWholeSalePriceSold'])) {
                $value['totalWholeSalePriceSold'] = $this->context->getCurrentLocale()->formatPrice($value['totalPriceSold'] - $value['totalWholeSalePriceSold'], $currency->iso_code);
            }
            $value['totalPriceSold'] = $this->context->getCurrentLocale()->formatPrice($value['totalPriceSold'], $currency->iso_code);
        }

        $this->_values = $values;
        $this->_totalCount = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('SELECT FOUND_ROWS()');
    }
}
