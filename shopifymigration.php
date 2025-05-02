<?php
/**
 * @author John C. Flores
 * http://duehome.devsoy.com/soyscripts/shopifymigration.php?token=XYYQTTERRRAS225699766524156UYYATSA&action=install
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 * *
*/

require_once("../config/config.inc.php");
define("HOSTNAME", "");
define("TOKEN", ""); 
define("KEY", "");


class shopifymigration {


    public function init() {

        if(Tools::getValue("token") !== 'XYYQTTERRRAS225699766524156UYYATSA'){
            die("bad token");
        }
        if(!Tools::getValue("action")) {
            die("action is null");
        }

        switch (Tools::getValue('action')) {
            case 'install':
                self::install();
                break;
            case 'uninstallproduct':
                self::uninstall('shopifyproductmigration');
                break;
            case 'uninstallcategory':
                self::uninstall('shopifycollectionmigration');
                break;
            case 'migrateproducts':
                self::migrateProducts();
                break;
            case 'migratecollections':
                self::migrateCollections();
                break;
            case 'printDataSync':
                self::printDataSync();
                break;

            default:
                die("bad action");
                break;
        }    
    }

    public function printDataSync(){
        $shopifyproductmigration=Db::getInstance()->executeS('SELECT * FROM '._DB_PREFIX_.'shopifyproductmigration');
        echo '<pre>';
        print_r($shopifyproductmigration);
        echo '===============<br>';
        
        $shopifycollectionmigration=Db::getInstance()->executeS('SELECT * FROM '._DB_PREFIX_.'shopifycollectionmigration');
        echo '<pre>';
        print_r($shopifycollectionmigration);

        exit();
    }

    public function migrateCollections() {
        $results=[];

        try {
            
            $categories=self::generateCollectionsJson();
            foreach ($categories as $key => $category) {
                $reference=$key;
                
                if(!Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'shopifycollectionmigration where reference_prestashop="'.$reference.'"')) {
                    $smart_collection=array('rules' => array(array('column' => 'tag',  'relation' => 'equals', 'condition' => $category)),
                                            'title'=> $category, 
                                            'disjunctive' => false,
                                            'sort_order' => 'best-selling'); 
                    $objCategory=array('smart_collection' => $smart_collection);
                    
                    $urlrequest='/admin/api/2025-01/smart_collections.json';
                    if($response=self::REST($urlrequest, 'POST', json_encode($objCategory))) {
                        $data=json_decode($response);
                        if(isset($data->smart_collection->id)) {
                            $sql='INSERT INTO '._DB_PREFIX_.'shopifycollectionmigration (id_shopify_collection, reference_prestashop) VALUES ('.$data->smart_collection->id.', "'.$reference.'")';
                            if(Db::getInstance()->execute($sql)) {
                                $results[]=array('id_shopify_collection' => $data->smart_collection->id, 'reference_prestashop' => $reference);
                                echo 'INSERTADO: '.$reference.' '.$category.'<br>';
                            }
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            echo $e->getMessage();
            die();
        }
    }

    public function generateCollectionsJson($id_lang=3) {
        $allCollections=[];
        $sql=self::getSQL($id_lang);
        $collections=Db::getInstance()->executeS($sql);

        foreach ($collections as $collection) {
            $categories=explode(',',$collection['collections']);
            foreach ($categories as $key => $category) {
                $idNameCategory=explode('-',$category);
                $idCategory=$idNameCategory[0];
                $nameCategory=$idNameCategory[1];
                if($idCategory!=1) {
                    if(!in_array($idCategory, $allCollections)) {
                        $allCollections[$idCategory]=$nameCategory;
                    }
                }
                
            }
        }
        return $allCollections;
    }

    public function migrateProducts() {
        $results=[];

        try {
            $products=self::generateProductsJson();
            foreach ($products as $key => $product) {
                $reference=$product['id_product'];
                unset($product['id_product']);
                if(!Db::getInstance()->getRow('SELECT * FROM '._DB_PREFIX_.'shopifyproductmigration where reference_prestashop="'.$reference.'"')) {
                    $objProduct=array('product' => $product);
                    $urlrequest='/admin/api/2025-01/products.json';
                    if($response=self::REST($urlrequest, 'POST', json_encode($objProduct))) {
                        $data=json_decode($response);
                        if(isset($data->product->id)) {
                            $sql='INSERT INTO '._DB_PREFIX_.'shopifyproductmigration (id_shopify_product, reference_prestashop) VALUES ('.$data->product->id.', "'.$reference.'")';
                            if(Db::getInstance()->execute($sql)) {
                                $results[]=array('id_shopify_product' => $data->product->id, 'reference_prestashop' => $reference);
                                echo 'INSERTADO: '.$reference.'<br>';
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            die();
        }
    }

    public function generateProductsJson($id_lang=3) {
        $allFeatures=[];
        $sql=self::getSQL($id_lang);
        $productos=Db::getInstance()->executeS($sql);
        
        $productos_json = [];
        foreach ($productos as $producto) {
            $metafields=[]; $variants=[]; $options=[]; $grupos=[]; $values=[];
            
            $metafields[]= array('namespace' => 'custom', 'key' => 'referencia', 'value' => $producto['reference'], "owner_resource" => "product", 'type' => 'single_line_text_field');

            $json_dc=array('type'=>'root','children'=>array(array('type'=>'paragraph', 'children'=>array(array('type'=>'text', 'value'=> strip_tags($producto['body_short_html']))))));
            $metafields[]= array('namespace' => 'custom', 'key' => 'descripcion_corta', 'value' => json_encode($json_dc, JSON_UNESCAPED_SLASHES), "owner_resource" => "product", 'type' => 'rich_text_field');

            $features=Product::getFrontFeaturesStatic($id_lang, $producto['id_product']);
            foreach ($features as $key => $feature) {
                if(!in_array($feature['name'], $allFeatures)) {
                    $allFeatures[$feature['id_feature']]=$feature['name'];
                    switch ($feature['id_feature']) {
                        case '10':
                            $key='ancho'; $value=$feature['value'];
                        break;
                        case '11':
                            $key='fondo'; $value=$feature['value'];
                        break;
                        case '9':
                            $key='altura'; $value=$feature['value'];
                        break;
                        case '12':    
                            $key='peso'; $value=$feature['value'];
                        break;
                        case '16':    
                            $key='colores'; $value=$feature['value'];
                        break;
                        case '17':    
                            $key='uso_en'; $value=$feature['value'];
                        break;
                        case '19':    
                            $key='materiales'; $value=$feature['value'];
                        break;
                        case '37':    
                            $key='fabricante'; $value=$feature['value'];
                        break;
                        case '23':    
                            $key='tipologia_de_mesa'; $value=$feature['value'];
                        break;
                        case '21':    
                            $key='composicion'; $value=$feature['value'];
                        break;
                        case '36':    
                            $key='largo'; $value=$feature['value'];
                        break;
                        case '26':    
                            $key='tipologia_de_cama'; $value=$feature['value'];
                        break;
                        case '35':    
                            $key='medida_ropa_de_cama'; $value=$feature['value'];
                        break;
                        case '14':    
                            $key='preparado_para_parquet'; $value=$feature['value'];
                        break;
                        case '22':    
                            $key='tecnologia'; $value=$feature['value'];
                        break;
                        case '18':    
                            $key='firmeza'; $value=$feature['value'];
                        break;
                    }
                    $metafields[]= array('namespace' => 'custom', 'key' => $key, 'value' => $value,  "owner_resource" => "product", 'type' => 'single_line_text_field');  
                }
            }
            
            $combinations=self::getCombinationByIdProduct($producto['id_product'], $id_lang);
            foreach ($combinations as $combination) {

                $reference=$combination['reference'];
                $ean13=$combination['ean13'];

                $combinations_raw = explode(',', $combination['combinaciones']);
                if(count($combinations_raw)==2) {

                    $grupoValor1 = explode(':', $combinations_raw[0]);
                    if(!in_array($grupoValor1[0], $grupos)) {
                        $grupos[]=$grupoValor1[0];
                    }

                    $option1=trim($grupoValor1[1]);
                    if(!in_array($option1, $values)) {
                        $values[]=$option1;
                    }

                    $grupoValor2 = explode(':', $combinations_raw[1]);
                    if(!in_array($grupoValor2[0], $grupos)) {
                        $grupos[]=$grupoValor2[0];
                    }
                    
                    $option2=trim($grupoValor2[1]);
                    if(!in_array($option2, $values)) {
                        $values[]=$option2;
                    }                   

                    $variants[] = [ "option1"   => $option1,
                                    "option2"   => $option2,
                                    "sku"       => $reference,
                                    "barcode"   => $ean13,
                                    "price"   => 0,
                                    "weight"   => $producto['weight'],
                                    "taxable" => true,
                                    "inventory_management" => "shopify",
                                    "requires_shipping" => true ];

                }else{ //siempre tendra un grupoName

                    $grupoValor = explode(':', $combination['combinaciones']);
                    
                    $grupoName= trim($grupoValor[0]);
                    if(!in_array($grupoValor[0], $grupos)) {
                        $grupos[] = $grupoName;
                    }

                    if(!in_array($grupoValor[1], $values)) {
                        $values[] = trim($grupoValor[1]);
                    }

                    $variants[] = [ "option1"   => trim($grupoValor[1]),
                                    "sku"       => $reference,
                                    "barcode"   => $ean13,
                                    "price"   => 0,
                                    "weight"   => $producto['weight'],
                                    "taxable" => true,
                                    "inventory_management" => "shopify",
                                    "requires_shipping" => true ];
                }

            }

            if(count($grupos)==1){
                $options[] = ['name' => $grupoName, 'position' =>1, 'values' => $values];    
            }else{
                foreach ($grupos as $key => $g) {
                    $options[] = ['name' => $g, 'position' => $key+1, 'values' => $values];
                }
            }

            $productos_json[] = [
                    "id_product"   => $producto['id_product'],
                    "title"        => $producto['title'],
                    "body_html"    => $producto['body_html'],
                    "vendor"       => $producto['vendor'],
                    "tags"         => $producto['tags'],
                    "status"       => $producto['status'] == 1 ? 'active' : 'draft',
                    "options"      => $options,
                    "variants"     => $variants,
                    "metafields"   => $metafields,
            ];
  
        }
        return $productos_json;
    }

    public function getSQL($id_lang=3) {
        //SELECT id_product FROM "._DB_PREFIX_."feature_product WHERE id_feature_value= 421956
        return "SELECT p.id_product AS id_product, p.active AS status, p.reference AS reference,
                        pl.name AS title, pl.description AS body_html,
                        pl.description_short AS body_short_html,
                        m.name AS vendor,
                    (
                        SELECT GROUP_CONCAT(DISTINCT cl2.name SEPARATOR ',')
                        FROM "._DB_PREFIX_."category_product cp2
                        JOIN "._DB_PREFIX_."category_lang cl2 ON cp2.id_category = cl2.id_category AND cl2.id_lang = ".$id_lang."
                        WHERE cp2.id_product = p.id_product
                    ) AS tags,
                    (
                        SELECT GROUP_CONCAT(DISTINCT CONCAT(cp2.id_category, '-', cl2.name) SEPARATOR ',')
                        FROM "._DB_PREFIX_."category_product cp2
                        JOIN "._DB_PREFIX_."category_lang cl2 ON cp2.id_category = cl2.id_category AND cl2.id_lang = ".$id_lang."
                        WHERE cp2.id_product = p.id_product
                    ) AS collections,
                    p.reference AS sku,
                    p.weight AS weight
                FROM "._DB_PREFIX_."product p
                JOIN "._DB_PREFIX_."product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = ".$id_lang."
                LEFT JOIN "._DB_PREFIX_."manufacturer m ON p.id_manufacturer = m.id_manufacturer
                LEFT JOIN "._DB_PREFIX_."category_product cp ON p.id_product = cp.id_product
                LEFT JOIN "._DB_PREFIX_."category_lang cl ON cp.id_category = cl.id_category AND cl.id_lang = ".$id_lang."
                WHERE p.id_product IN (SELECT id_product FROM "._DB_PREFIX_."feature_product WHERE id_feature_value= 421956)
                GROUP BY p.id_product";
    }

    public function getCombinationByIdProduct($id_product, $id_lang=1) {
        $sql='SELECT ppa.id_product_attribute, ppa.reference, ppa.ean13, ppa.id_product,
                    GROUP_CONCAT(CONCAT(pagll.name, ": ", pal.name) ORDER BY pagr.position, pa.position) AS combinaciones
                    FROM '._DB_PREFIX_.'product_attribute ppa INNER JOIN '._DB_PREFIX_.'product_attribute_combination ppac ON ppa.id_product_attribute = ppac.id_product_attribute
                    INNER JOIN '._DB_PREFIX_.'attribute pa ON ppac.id_attribute = pa.id_attribute
                    INNER JOIN '._DB_PREFIX_.'attribute_lang pal ON pa.id_attribute = pal.id_attribute
                    INNER JOIN '._DB_PREFIX_.'attribute_group pagr ON pa.id_attribute_group = pagr.id_attribute_group
                    INNER JOIN '._DB_PREFIX_.'attribute_group_lang pagll ON pagr.id_attribute_group = pagll.id_attribute_group AND pal.id_lang = pagll.id_lang
                    WHERE ppa.id_product='.$id_product.'
                    AND pal.id_lang='.$id_lang.'  
                    GROUP BY ppa.id_product_attribute 
                    ORDER BY ppa.id_product_attribute;';
        return Db::getInstance()->executeS($sql);
    }

    public function REST($urlrequest, $method, $data=[]) {
        $hostname=HOSTNAME; $token=TOKEN; $key=KEY;
        $url='https://'.$key.':'.$token.'@'.$hostname.$urlrequest;
        $curl = curl_init();
        switch ($method) {
           case "POST":
              curl_setopt($curl, CURLOPT_POST, 1);
              if ($data)
                 curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
              break;
           case "PUT":
              curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
              if ($data)
                 curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
              break;
        case "DELETE":
           curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
           break;
           default:
              if ($data)
                 $url = sprintf("%s?%s", $url, http_build_query($data));
        }
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        $response = curl_exec($curl);
        curl_close($curl);
        return $response;
    }

    public function install() {
        $tables[]='CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.'shopifyproductmigration (
            `id_shopifyproductmigration` bigint(20) NOT NULL AUTO_INCREMENT,
            `id_shopify_product` bigint(20) NOT NULL,
            `reference_prestashop` varchar(255) NOT NULL,
             PRIMARY KEY (`id_shopifyproductmigration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

        $tables[]='CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.'shopifycollectionmigration (
            `id_shopifycollectionmigration` bigint(20) NOT NULL AUTO_INCREMENT,
            `id_shopify_collection` bigint(20) NOT NULL,
            `reference_prestashop` varchar(255) NOT NULL,
             PRIMARY KEY (`id_shopifycollectionmigration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

        foreach ($tables as $table){
            if (!Db::getInstance()->Execute($table)){
                echo 'Error creating table: '.$table.'<br>';
            }
        }
        echo 'Tables created successfully<br>';
    }

    public function uninstall($table) {
        
        if(Db::getInstance()->execute('TRUNCATE TABLE '._DB_PREFIX_.$table)) {
            echo 'Table '.$table.' truncated successfully<br>';
        }

    }

}

$shopifymigration = new shopifymigration();
$shopifymigration->init();

