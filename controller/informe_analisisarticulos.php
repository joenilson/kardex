<?php

/*
 * Copyright (C) 2016 Joe Nilson             <joenilson@gmail.com>
 * Copyright (C) 2016 Carlos García Gómez    <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See th * e
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_model('familias.php');
require_model('articulo.php');
require_model('almacen.php');
require_model('albaran_cliente.php');
require_model('albaran_proveedor.php');
require_model('cliente.php');
require_model('factura_cliente.php');
require_model('factura_proveedor.php');
require_model('forma_pago.php');
require_model('pais.php');
require_model('proveedor.php');
require_model('serie.php');
require_model('kardex.php');
require_once 'plugins/facturacion_base/extras/xlsxwriter.class.php';
require_once 'plugins/kardex/vendor/php-i18n/i18n.class.php';

/**
 * Description of informe_analisisarticulos
 *
 * @author Joe Nilson <joenilson@gmail.com>
 */
class informe_analisisarticulos extends fs_controller {
    public $resultados;
    public $resultados_almacen;
    public $total_resultados;
    public $familia;
    public $familias;
    public $articulo;
    public $articulos;
    public $fecha_inicio;
    public $fecha_fin;
    public $almacen;
    public $almacenes;
    public $stock;
    public $lista;
    public $i18n_controller;
    public $lista_almacenes;
    public $lista_resultados;
    public $pathName;
    public $fileName;
    public $documentosDir;
    public $kardexDir;
    public $publicPath;
    public $writer;
    public $kardex;
    public $mostrar;
    public $valorizado;
    public $kardex_setup;
    public $kardex_ultimo_proceso;
    public $kardex_procesandose;
    public $kardex_usuario_procesando;
    public $kardex_programado;
    public $loop_horas;
    public $tablas;
    public function __construct() {
        parent::__construct(__CLASS__, "Kardex", 'informes', FALSE, TRUE);
    }

    protected function private_core() {
        $this->familias = new familia();
        $this->articulos = new articulo();
        $this->almacenes = new almacen();
        $this->kardex = new kardex();
        $this->share_extension();
        $lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        $this->user_lang = (!isset($this->user->lang)) ? $lang : 'es';
        $this->language($this->user_lang);
        $this->fecha_inicio = \date('01-m-Y');
        $this->fecha_fin = \date('t-m-Y');
        $this->reporte = '';
        $this->total_resultados = 0;
        $this->resultados_almacen = '';
        $this->mostrar = 'todo';
        $this->valorizado = false;
        $this->fileName = '';
        $basepath = dirname(dirname(dirname(__DIR__)));
        $this->documentosDir = $basepath . DIRECTORY_SEPARATOR . FS_MYDOCS . 'documentos';
        $this->kardexDir = $this->documentosDir . DIRECTORY_SEPARATOR . "kardex";
        $this->publicPath = FS_PATH . FS_MYDOCS . 'documentos' . DIRECTORY_SEPARATOR . 'kardex';
        $this->tablas = $this->db->list_tables();
        $fsvar = new fs_var();
        
        if (!is_dir($this->documentosDir)) {
            mkdir($this->documentosDir);
        }

        if (!is_dir($this->kardexDir)) {
            mkdir($this->kardexDir);
        }
       
        //Creamos un array para el selector de horas para cron
        for ($x = 0; $x < 25; $x++) {
            $this->loop_horas[] = str_pad($x, 2, "0", STR_PAD_LEFT);
        }

        $cancelar_kardex = \filter_input(INPUT_GET, 'cancelar_kardex');
        if (!empty($cancelar_kardex)) {
            $fsvar->array_save(array(
                'kardex_procesandose' => 'FALSE',
                'kardex_usuario_procesando' => ''
            ));
        }
        
        $this->kardex_setup = $fsvar->array_get(
            array(
                'kardex_ultimo_proceso' => '',
                'kardex_cron' => '',
                'kardex_programado' => '',
                'kardex_procesandose' => 'FALSE',
                'kardex_usuario_procesando' => ''
            ), FALSE
        );

        $comparacion_fechas = (date('Y-m-d', strtotime($this->kardex_setup['kardex_ultimo_proceso'])) == date('Y-m-d', strtotime($this->kardex->ultimo_proceso())));
        $this->kardex_ultimo_proceso = ($comparacion_fechas) ? $this->kardex_setup['kardex_ultimo_proceso'] : $this->kardex->ultimo_proceso();
        $this->kardex_procesandose = ($this->kardex_setup['kardex_procesandose'] == 'TRUE') ? TRUE : FALSE;
        $this->kardex_usuario_procesando = ($this->kardex_setup['kardex_usuario_procesando']) ? $this->kardex_setup['kardex_usuario_procesando'] : FALSE;
        $this->kardex_cron = $this->kardex_setup['kardex_cron'];
        $this->kardex_programado = $this->kardex_setup['kardex_programado'];
        
        $procesar_reporte = \filter_input(INPUT_POST, 'procesar-reporte');
        if (!empty($procesar_reporte)) {
            $inicio = \date('Y-m-d', strtotime(\filter_input(INPUT_POST, 'inicio')));
            $fin = \date('Y-m-d', strtotime(\filter_input(INPUT_POST, 'fin')));
            $almacen = \filter_input(INPUT_POST, 'almacen');
            $familia = \filter_input(INPUT_POST, 'familia');
            $articulo = \filter_input(INPUT_POST, 'articulo');
            $mostrar = \filter_input(INPUT_POST, 'mostrar');
            $valorizado = \filter_input(INPUT_POST, 'valorizado');
            $this->mostrar = ($mostrar)?$mostrar:$this->mostrar;
            $this->valorizado = ($valorizado=='true')?TRUE:$this->valorizado;
            $this->fecha_inicio = $inicio;
            $this->fecha_fin = $fin;
            $this->reporte = $procesar_reporte;
            $this->almacen = ($almacen != 'null') ? $this->comma_separated_to_array($almacen) : NULL;
            $this->familia = ($familia != 'null') ? $this->comma_separated_to_array($familia) : NULL;
            $this->articulo = ($articulo != 'null') ? $this->comma_separated_to_array($articulo) : NULL;
            $this->kardex_almacen();
        }

        $kardex = \filter_input(INPUT_GET, 'procesar-kardex');
        if (!empty($kardex)) {
            $kardex_inicio = \filter_input(INPUT_GET, 'kardex_inicio');
            $kardex_fin = \filter_input(INPUT_GET, 'kardex_fin');
            $k = new kardex();
            if (!empty($kardex_inicio)) {
                $k->fecha_inicio = $kardex_inicio;
                $k->fecha_fin = $kardex_fin;
            }
            $this->template = false;
            header('Content-Type: application/json');
            $k->procesar_kardex($this->user->nick);
        }

        $opciones_kardex = \filter_input(INPUT_POST, 'opciones-kardex');
        if (!empty($opciones_kardex)) {
            $data = array();
            $op_kardex_cron = \filter_input(INPUT_POST, 'kardex_cron');
            $op_kardex_programado = \filter_input(INPUT_POST, 'kardex_programado');
            $kardex_cron = ($op_kardex_cron == 'TRUE') ? "TRUE" : "FALSE";
            $kardex_programado = $op_kardex_programado;
            $kardex_config = array(
                'kardex_cron' => $kardex_cron,
                'kardex_programado' => $kardex_programado
            );
            if ($fsvar->array_save($kardex_config)) {
                $data['success'] = true;
                $data['mensaje'] = K::kardex_CambiosGrabadosCorrectamente;
            } else {
                $data['success'] = false;
                $data['mensaje'] = K::kardex_CambiosNoGrabados;
            }
            $this->template = false;
            header('Content-Type: application/json');
            echo json_encode($data);
        }
        
        $type = \filter_input(INPUT_GET, 'type');
        if($type=='buscar-articulos'){
            $articulos = new articulo();
            $query = \filter_input(INPUT_POST, 'q');
            $data = $articulos->search($query);
            $this->template = false;
            header('Content-Type: application/json');
            echo json_encode($data);
        }
    }

    public function kardex_almacen() {
        $resumen = array();
        $this->pathName = $this->kardexDir . DIRECTORY_SEPARATOR . K::kardex_Kardex . "_" . $this->user->nick . ".xlsx";
        $this->fileName = $this->publicPath . DIRECTORY_SEPARATOR . K::kardex_Kardex . "_" . $this->user->nick . ".xlsx";
        if (file_exists($this->fileName)) {
            unlink($this->fileName);
        }
        
        $this->estilo_cabecera = array('border'=>'left,right,top,bottom','font-style'=>'bold');
        $this->estilo_cuerpo = array( array('halign'=>'left'),array('halign'=>'right'),array('halign'=>'center'),array('halign'=>'none'));
        $this->estilo_pie = array('border'=>'left,right,top,bottom','font-style'=>'bold','color'=>'#FFFFFF','fill'=>'#000000');
        
        $header[K::kardex_Fecha] = '';
        $header[K::kardex_Documento] = 'string';
        $header[K::kardex_Numero] = '';
        $header[K::kardex_Referencia] = 'string';
        $header[K::kardex_Articulo] = 'string';
        $header[K::kardex_Salida] = '#,###,###.##';
        if($this->valorizado){
            $header[K::kardex_SalidaValorizada] = '#,###,###.##';
        }
        $header[K::kardex_Ingreso] = '#,###,###.##';
        if($this->valorizado){
            $header[K::kardex_IngresoValorizado] = '#,###,###.##';
        }
        $header[K::kardex_Saldo] = '#,###,###.##';
        if($this->valorizado){
            $header[K::kardex_SaldoValorizado] = '#,###,###.##';
        }
        $this->header = array();
        $this->header[] = K::kardex_Fecha;
        $this->header[] = K::kardex_Documento;
        $this->header[] = K::kardex_Numero;
        $this->header[] = K::kardex_Referencia;
        $this->header[] = K::kardex_Articulo;
        $this->header[] = K::kardex_Salida;
        if($this->valorizado){
            $this->header[] = K::kardex_SalidaValorizada;
        }
        $this->header[] = K::kardex_Ingreso;
        if($this->valorizado){
            $this->header[] = K::kardex_IngresoValorizado;
        }
        $this->header[] = K::kardex_Saldo;
        if($this->valorizado){
            $this->header[] = K::kardex_SaldoValorizado;
        }
        
        $this->writer = new XLSXWriter();

        foreach ($this->almacen as $index => $codigo) {
            $almacen0 = $this->almacenes->get($codigo);
            $this->writer->writeSheetHeader($almacen0->nombre, array(), true);
            $resumen = array_merge($resumen, $this->stock_query($almacen0));
        }
        $this->writer->writeToFile($this->pathName);
        gc_collect_cycles();
        $this->resultados_almacen = $resumen;
        $data['rows'] = $resumen;
        $data['filename'] = $this->fileName;
        $this->template = false;
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    public function stock_query($almacen) {
        //Validamos el listado de Familias seleccionadas
        $codfamilia = ($this->familia) ? " and codfamilia IN ({$this->familia_data()})" : "";

        //Validamos el listado de Productos seleccionados
        $referencia = ($this->articulo) ? " and referencia IN ({$this->articulo_data()})" : "";

        //Generamos el select para la subconsulta
        $articulos = "SELECT referencia FROM articulos where bloqueado = false and nostock = false $codfamilia $referencia";
        $this->lista = array();
        
        if($this->mostrar=='todo'){
            $this->SaldoInicial($almacen,$articulos);
        }
        
        if($this->mostrar == 'todo' OR $this->mostrar=='compra'){
            $this->Compras($almacen,$articulos);
        }
        if($this->mostrar == 'todo' OR $this->mostrar=='devolucion-compra'){
            $this->devolucionCompras($almacen,$articulos);
        }
        if($this->mostrar == 'todo' OR $this->mostrar=='regularizacion'){
            $this->Regularizaciones($almacen,$articulos);
        }
        if($this->mostrar == 'todo' OR $this->mostrar=='transferencia'){
            $this->Transferencias($almacen,$articulos);
        }
        if($this->mostrar == 'todo' OR $this->mostrar=='venta'){
            $this->ConducesSinFactura($almacen,$articulos);
            $this->Ventas($almacen,$articulos);
        }
        if($this->mostrar == 'todo' OR $this->mostrar=='devolucion-venta'){
            $this->devolucionVentas($almacen,$articulos);
        }
        if($this->mostrar == 'todo' OR $this->mostrar=='ofertas-venta'){
            $this->ofertasVentas($almacen,$articulos);
        }
        
        return $this->generar_resultados($almacen);
    }
    
    public function SaldoInicial($almacen,$articulos=false){
        /*
         * Obtenemos el saldo inicial para el rango de fechas de la tabla de Inventario Diario
         */
        $art0 = new articulo();
        foreach($this->db->select($articulos) as $d)
        {
            $resultados = array();
            $art = $art0->get($d['referencia']);
            $saldo = $this->kardex->saldo_articulo($art->referencia, $almacen->codalmacen, $this->fecha_inicio);
            $resultados['codalmacen'] = $almacen->codalmacen;
            $resultados['nombre'] = $almacen->nombre;
            $resultados['fecha'] = $this->fecha_inicio;
            $resultados['tipo_documento'] = K::kardex_SaldoInicial;
            $resultados['documento'] = 'STOCK';
            $resultados['referencia'] = $art->referencia;
            $resultados['descripcion'] = $art->referencia.' - '.$art->descripcion;
            $resultados['saldo_cantidad'] = $saldo;
            $resultados['salida_cantidad'] = 0;
            $resultados['ingreso_cantidad'] = 0;
            if($this->valorizado){
                $resultados['saldo_monto'] = $saldo * $art->costemedio;
                $resultados['salida_monto'] = 0;
                $resultados['ingreso_monto'] = 0;
            }
            $this->lista[$this->fecha_inicio][] = $resultados;
            $this->total_resultados++;
        }
    }
    
    /**
     * Con esta funcion se genera la información para el Inventario de Artículos
     * @param type $almacen object \FacturaScripts\model\core\almacen
     * @param type $data array
     * @param type $documento string
     * @param type $tipo string
     */
    public function procesar_informacion($almacen,$data,$documento,$tipo){
        $resultados = array();
        foreach ($data as $linea) {
            if(!isset($resultados[$linea['documento']]['salida_cantidad'])){
                $resultados[$linea['documento']]['salida_cantidad'] = 0;
            }
            if(!isset($resultados[$linea['documento']]['ingreso_cantidad'])){
                $resultados[$linea['documento']]['ingreso_cantidad'] = 0;
            }
            if(!isset($resultados[$linea['documento']]['salida_monto'])){
                $resultados[$linea['documento']]['salida_monto'] = 0;
            }
            if(!isset($resultados[$linea['documento']]['ingreso_monto'])){
                $resultados[$linea['documento']]['ingreso_monto'] = 0;
            }
            $idlinea = \date('Y-m-d H:i:s',strtotime($linea['fecha']." ".$linea['hora']));
            if($this->valorizado){
                $linea['monto'] = ($linea['coddivisa'] != $this->empresa->coddivisa) ? $this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')) : $linea['monto'];
                if($tipo=='ingreso'){
                    $resultados[$linea['documento']]['salida_monto'] = 0;
                    $resultados[$linea['documento']]['ingreso_monto'] = $linea['monto'];
                }elseif($tipo=='salida'){
                    $resultados[$linea['documento']]['salida_monto'] = $linea['monto'];
                    $resultados[$linea['documento']]['ingreso_monto'] = 0;
                }elseif($tipo=='salida_no_facturada'){
                    $resultados[$linea['documento']]['salida_monto'] = $linea['monto'];
                    $resultados[$linea['documento']]['ingreso_monto'] = 0;
                }
            }
            $resultados[$linea['documento']]['codalmacen'] = $linea['codalmacen'];
            $resultados[$linea['documento']]['nombre'] = $almacen->nombre;
            $resultados[$linea['documento']]['fecha'] = $linea['fecha'];
            $resultados[$linea['documento']]['tipo_documento'] = $documento. " ".$linea['codigo'];
            $resultados[$linea['documento']]['documento'] = $linea['documento'];
            $resultados[$linea['documento']]['referencia'] = $linea['referencia'];
            $resultados[$linea['documento']]['descripcion'] = $linea['referencia'].' - '.stripcslashes($linea['descripcion']);
            if($tipo=='ingreso'){
                $resultados[$linea['documento']]['salida_cantidad'] = 0;
                $resultados[$linea['documento']]['ingreso_cantidad'] = $linea['cantidad'];
            }elseif($tipo=='salida'){
                $resultados[$linea['documento']]['salida_cantidad'] = $linea['cantidad'];
                $resultados[$linea['documento']]['ingreso_cantidad'] = 0;
            }elseif($tipo=='salida_no_facturada'){
                $resultados[$linea['documento']]['salida_cantidad'] = $linea['cantidad'];
                $resultados[$linea['documento']]['ingreso_cantidad'] = 0;
            }
            
            $this->lista[$idlinea][] = $resultados[$linea['documento']];
            $this->total_resultados++;
        }
    }

    public function Compras($almacen,$productos) {
        /*
         * Generamos la informacion de los albaranes de proveedor asociados a facturas no anuladas
         */
        $sql_albaranes = "select codalmacen,ac.fecha,ac.hora,ac.codigo,ac.idalbaran as documento,a.referencia,a.descripcion, coddivisa, tasaconv, sum(cantidad) as cantidad, sum(pvptotal) as monto
        from albaranesprov as ac
        join lineasalbaranesprov as l ON (ac.idalbaran=l.idalbaran)
        JOIN articulos as a ON(a.referencia = l.referencia)
        where codalmacen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
        and idfactura is not null
        and l.referencia in ($productos)
        group by codalmacen,ac.fecha,ac.hora,ac.codigo,ac.idalbaran,a.referencia,a.descripcion, coddivisa, tasaconv
        order by codalmacen,a.referencia,fecha,hora;";
        $data1 = $this->db->select($sql_albaranes);
        if ($data1) {
            $this->procesar_informacion($almacen, $data1, ucfirst(FS_ALBARAN) . " " . K::kardex_Compra,'ingreso');
        }
        
        /*
         * Generamos la informacion de los albaranes de proveedor asociados a facturas no anuladas
         */
        $sql_albaranes = "select codalmacen,ac.fecha,ac.hora,ac.codigo,ac.idalbaran as documento,a.referencia,a.descripcion, coddivisa, tasaconv, sum(cantidad) as cantidad, sum(pvptotal) as monto
        from albaranesprov as ac
        join lineasalbaranesprov as l ON (ac.idalbaran=l.idalbaran)
        JOIN articulos as a ON(a.referencia = l.referencia)
        where codalmacen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
        and idfactura is null
        and l.referencia in ($productos)
        group by codalmacen,ac.fecha,ac.hora,ac.codigo,ac.idalbaran,a.referencia,a.descripcion, coddivisa, tasaconv
        order by codalmacen,a.referencia,fecha,hora;";
        $data1 = $this->db->select($sql_albaranes);
        if ($data1) {
            $this->procesar_informacion($almacen, $data1, ucfirst(FS_ALBARAN) . " " . K::kardex_Compra,'ingreso');
        }

        /*
         * Generamos la informacion de las facturas de proveedor ingresadas
         * que no esten asociadas a un albaran de proveedor
         */
        $sql_facturasprov = "select codalmacen,fc.fecha,fc.hora,fc.codigo,fc.idfactura as documento,referencia,descripcion, coddivisa, tasaconv,sum(cantidad) as cantidad, sum(pvptotal) as monto
        from facturasprov as fc
        join lineasfacturasprov as l ON (fc.idfactura=l.idfactura)
        where codalmacen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
        and anulada=FALSE and idalbaran is null and idfacturarect IS NULL 
        and l.referencia in ($productos)
        group by codalmacen,fc.fecha,fc.hora,fc.codigo,fc.idfactura,referencia,descripcion, coddivisa, tasaconv
        order by codalmacen,referencia,fecha,hora;";
        $data2 = $this->db->select($sql_facturasprov);
        if ($data2) {
            $this->procesar_informacion($almacen,$data2,ucfirst(FS_FACTURA) . " " . K::kardex_Compra,'ingreso');
        }
    }

    public function devolucionCompras($almacen,$productos) {
        /*
         * Generamos la informacion de las facturas de proveedor ingresadas
         * que no esten asociadas a un albaran de proveedor
         */
        $sql_facturasprov = "select codalmacen,fc.fecha,fc.hora,fc.codigo,fc.idfactura as documento,referencia,descripcion, coddivisa, tasaconv,sum(cantidad) as cantidad, sum(pvptotal) as monto
        from facturasprov as fc
        join lineasfacturasprov as l ON (fc.idfactura=l.idfactura)
        where codalmacen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
        and anulada=FALSE and idalbaran is null and idfacturarect IS NOT NULL
        and l.referencia in ($productos)
        group by codalmacen,fc.fecha,fc.hora,fc.codigo,fc.idfactura,referencia,descripcion, coddivisa, tasaconv
        order by codalmacen,referencia,fecha,hora;";
        $data2 = $this->db->select($sql_facturasprov);
        if ($data2) {
            $this->procesar_informacion($almacen,$data2,ucfirst(FS_FACTURA) . " " . K::kardex_Compra,'ingreso');
        }
    }

    public function ConducesSinFactura($almacen,$productos) {
        /*
         * Generamos la informacion de los albaranes no asociados a facturas
         */
        $sql_albaranes_sin_factura = "select codalmacen,ac.fecha,ac.hora,ac.codigo,ac.idalbaran as documento,referencia,descripcion,coddivisa, tasaconv,observaciones,sum(cantidad) as cantidad, sum(pvptotal) as monto
        from albaranescli as ac
        join lineasalbaranescli as l ON (ac.idalbaran=l.idalbaran)
        where codalmacen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
        and idfactura is null
        and l.referencia in ($productos)
        group by codalmacen,ac.fecha,ac.hora,ac.codigo, ac.idalbaran,referencia,descripcion,coddivisa, tasaconv,observaciones
        order by codalmacen,referencia,fecha,hora;";
        $data2 = $this->db->select($sql_albaranes_sin_factura);
        if ($data2) {
            $this->procesar_informacion($almacen,$data2,ucfirst(FS_ALBARAN) . " " . K::kardex_VentaNoFacturada,'salida_no_facturada');
        }
    }
    
    public function Ventas($almacen,$productos) {
        /*
         * Generamos la informacion de los albaranes asociados a facturas no anuladas
         */
        $sql_albaranes = "select codalmacen,ac.fecha,ac.hora,ac.codigo,ac.idalbaran AS documento,referencia,descripcion,coddivisa, tasaconv,sum(cantidad) as cantidad, sum(pvptotal) as monto
        from albaranescli as ac
        join lineasalbaranescli as l ON (ac.idalbaran=l.idalbaran)
        where codalmacen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
        and idfactura is not null
        and l.referencia in ($productos)
        group by codalmacen,ac.fecha,ac.hora,ac.codigo,ac.idalbaran,referencia,descripcion,coddivisa,tasaconv
        order by codalmacen,referencia,fecha,hora;";
        $data1 = $this->db->select($sql_albaranes);
        if ($data1) {
            $this->procesar_informacion($almacen,$data1,ucfirst(FS_ALBARAN) . " " . K::kardex_Venta,'salida');
        }

        /*
         * Generamos la informacion de las facturas que se han generado sin albaran y que son de venta
         */
        $sql_facturas = "select codalmacen,fc.fecha,fc.hora,fc.codigo,fc.idfactura as documento,referencia,descripcion,descripcion,coddivisa,sum(cantidad) as cantidad, sum(pvptotal) as monto
        from facturascli as fc
        join lineasfacturascli as l ON (fc.idfactura=l.idfactura)
        where codalmacen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
        and anulada=FALSE and idalbaran is null and idfacturarect IS NULL 
        and l.referencia in ($productos) and dtopor != 100 
        group by codalmacen,fc.codigo,fc.fecha,fc.hora,fc.idfactura,referencia,descripcion,descripcion,coddivisa
        order by codalmacen,referencia,fecha,hora;";
        $data3 = $this->db->select($sql_facturas);
        if ($data3) {
            $this->procesar_informacion($almacen,$data3,ucfirst(FS_FACTURA) . " " . K::kardex_Venta,'salida');
        }
    }
    
    public function ofertasVentas($almacen,$productos) {
        /*
         * Generamos la informacion de los albaranes asociados a facturas no anuladas con lineas de oferta
         */
        $sql_albaranes = "select codalmacen,ac.fecha,ac.hora,ac.codigo,ac.idalbaran AS documento,referencia,descripcion,coddivisa, tasaconv,sum(cantidad) as cantidad, sum(pvptotal) as monto
        from albaranescli as ac
        join lineasalbaranescli as l ON (ac.idalbaran=l.idalbaran)
        where codalmacen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
        and idfactura is not null and dtopor = 100 
        and l.referencia in ($productos)
        group by codalmacen,ac.fecha,ac.hora,ac.codigo,ac.idalbaran,referencia,descripcion,coddivisa,tasaconv
        order by codalmacen,referencia,fecha,hora;";
        $data1 = $this->db->select($sql_albaranes);
        if ($data1) {
            $this->procesar_informacion($almacen,$data1,ucfirst(FS_ALBARAN) . " " . K::kardex_Venta,'salida');
        }

        /*
         * Generamos la informacion de las facturas que se han generado sin albaran y que son de venta
         */
        $sql_facturas = "select codalmacen,fc.fecha,fc.hora,fc.codigo,fc.idfactura as documento,referencia,descripcion,descripcion,coddivisa,sum(cantidad) as cantidad, sum(pvptotal) as monto
        from facturascli as fc
        join lineasfacturascli as l ON (fc.idfactura=l.idfactura)
        where codalmacen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
        and anulada=FALSE and idalbaran is null and idfacturarect IS NULL and dtopor = 100 
        and l.referencia in ($productos) 
        group by codalmacen,fc.codigo,fc.fecha,fc.hora,fc.idfactura,referencia,descripcion,descripcion,coddivisa
        order by codalmacen,referencia,fecha,hora;";
        $data3 = $this->db->select($sql_facturas);
        if ($data3) {
            $this->procesar_informacion($almacen,$data3,ucfirst(FS_FACTURA) . " " . K::kardex_Venta,'salida');
        }
    }

    public function devolucionVentas($almacen,$productos) {
        /*
         * Generamos la informacion de las facturas que se han generado sin albaran y que son de venta
         */
        $sql_facturas = "select codalmacen,fc.fecha,fc.hora,fc.codigo,fc.idfactura as documento,referencia,descripcion,descripcion,coddivisa,sum(cantidad) as cantidad, sum(pvptotal) as monto
        from facturascli as fc
        join lineasfacturascli as l ON (fc.idfactura=l.idfactura)
        where codalmacen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
        and anulada=FALSE and idalbaran is null and idfacturarect IS NOT NULL 
        and l.referencia in ($productos)
        group by codalmacen,fc.fecha,fc.hora,fc.codigo,fc.idfactura,referencia,descripcion,descripcion,coddivisa
        order by codalmacen,referencia,fecha,hora;";
        $data = $this->db->select($sql_facturas);
        if ($data) {
            $this->procesar_informacion($almacen,$data,K::kardex_Devolucion . " " . K::kardex_Venta,'salida');
        }
    }

    public function Transferencias($almacen,$productos) {
        //Si existen estas tablas sacamos la información
        if( $this->db->table_exists('transstock', $this->tablas) AND $this->db->table_exists('lineastransstock', $this->tablas) ){
            /*
             * Generamos la informacion de las transferencias por salida que se hayan hecho a los stocks
             */
            $sql_regstocks = "select codalmaorigen, fecha, hora,l.idtrans as documento, a.referencia, sum(cantidad) as cantidad, a.descripcion, (cantidad * costemedio) as monto
            from lineastransstock AS ls
            JOIN transstock as l ON(ls.idtrans = l.idtrans)
            JOIN articulos as a ON(a.referencia = ls.referencia)
            where codalmaorigen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
            and ls.referencia IN ($productos)
            group by l.codalmaorigen, fecha, hora,l.idtrans, a.referencia, a.descripcion, costemedio
            order by codalmaorigen,a.referencia,fecha,hora;";
            $data = $this->db->select($sql_regstocks);
            if ($data) {
                $this->procesar_informacion($almacen,$data,K::kardex_Transferencia,'salida');
            }

            /*
             * Generamos la informacion de las transferencias por ingresos que se hayan hecho a los stocks
             */
            $sql_regstocks = "select codalmadestino, fecha, hora, l.idtrans as documento, a.referencia, sum(cantidad) as cantidad, a.descripcion,  (cantidad * costemedio) as monto
            from lineastransstock AS ls
            JOIN transstock as l ON(ls.idtrans = l.idtrans)
            JOIN articulos as a ON(a.referencia = ls.referencia)
            where codalmadestino = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
            and ls.referencia IN ($productos)
            group by l.codalmadestino, fecha, hora, l.idtrans, a.referencia, a.descripcion, costemedio
            order by codalmadestino,a.referencia,fecha,hora;";
            $data2 = $this->db->select($sql_regstocks);
            if ($data2) {
                $this->procesar_informacion($almacen,$data2,K::kardex_Transferencia,'ingreso');
            }
        }
    }

    public function Regularizaciones($almacen,$productos) {
        /*
         * Generamos la informacion de las regularizaciones que se hayan hecho a los stocks
         */
        $sql_regstocks = "select codalmacen, fecha, hora, l.idstock as documento, a.referencia, motivo, sum(cantidadfin) as cantidad, descripcion, costemedio
        from lineasregstocks AS ls
        JOIN stocks as l ON(ls.idstock = l.idstock)
        JOIN articulos as a ON(a.referencia = l.referencia)
        where codalmacen = '" . stripcslashes(strip_tags(trim($almacen->codalmacen))) . "' AND fecha between '" . $this->fecha_inicio . "' and '" . $this->fecha_fin . "'
        and l.referencia IN ($productos)
        group by l.codalmacen, fecha, hora, l.idstock, a.referencia, motivo, descripcion, costemedio
        order by codalmacen,a.referencia,fecha,hora;";
        $data = $this->db->select($sql_regstocks);
        if ($data) {
            foreach ($data as $linea) {
                if(!isset($resultados[$linea['documento']]['salida_cantidad'])){
                    $resultados[$linea['documento']]['salida_cantidad'] = 0;
                }
                if(!isset($resultados[$linea['documento']]['ingreso_cantidad'])){
                    $resultados[$linea['documento']]['ingreso_cantidad'] = 0;
                }
                if(!isset($resultados[$linea['documento']]['salida_monto'])){
                    $resultados[$linea['documento']]['salida_monto'] = 0;
                }
                if(!isset($resultados[$linea['documento']]['ingreso_monto'])){
                    $resultados[$linea['documento']]['ingreso_monto'] = 0;
                }               
                $idlinea = \date('Y-m-d H:i:s',strtotime($linea['fecha']." ".$linea['hora']));
                $resultados[$linea['documento']]['codalmacen'] = $linea['codalmacen'];
                $resultados[$linea['documento']]['nombre'] = $almacen->nombre;
                $resultados[$linea['documento']]['fecha'] = $linea['fecha'];
                $resultados[$linea['documento']]['tipo_documento'] = K::kardex_Regularizacion;
                $resultados[$linea['documento']]['documento'] = $linea['documento'];
                $resultados[$linea['documento']]['referencia'] = $linea['referencia'];
                $resultados[$linea['documento']]['descripcion'] = $linea['referencia'].' - '.$linea['descripcion'];
                $resultados[$linea['documento']]['regularizacion_cantidad'] = $linea['cantidad'];
                $resultados[$linea['documento']]['salida_cantidad'] = 0;
                $resultados[$linea['documento']]['ingreso_cantidad'] = 0;
                if($this->valorizado){
                    $resultados[$linea['documento']]['regularizacion_monto'] = $linea['costemedio'] * $linea['cantidad'];
                    $resultados[$linea['documento']]['salida_monto'] = 0;
                    $resultados[$linea['documento']]['ingreso_monto'] = 0;
                }
                $this->lista[$idlinea][] = $resultados[$linea['documento']];
                $this->total_resultados++;
            }
        }
    }

    public function generar_resultados($almacen) {
        $linea_resultado = array();
        $this->lista_resultado = array();
        $cabecera_export = array();
        $lista_export = array();
        $resumen = array();
        ksort($this->lista);
        $id_linea = 1;
        $saldoInicial = array();
        foreach ($this->lista as $fecha) {
            foreach ($fecha as $value) {
                $value['id'] = $id_linea++;
                if(!isset($saldoInicial[$value['referencia']])){
                    $saldoInicial[$value['referencia']] = 0;
                }
                $saldoInicial[$value['referencia']] = ($value['tipo_documento'] == K::kardex_SaldoInicial) ? $value['saldo_cantidad'] : $saldoInicial[$value['referencia']];
                
                if (!isset($resumen[$value['codalmacen']][$value['referencia']]['saldo_cantidad'])) {
                    $resumen[$value['codalmacen']][$value['referencia']]['saldo_cantidad'] = 0;
                }
                if (!isset($resumen[$value['codalmacen']][$value['referencia']]['saldo_monto'])) {
                    $resumen[$value['codalmacen']][$value['referencia']]['saldo_monto'] = 0;
                }
                if (!isset($lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['saldo_monto'])) {
                    $lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['saldo_monto'] = 0;
                }
                if (!isset($lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['salida_monto'])) {
                    $lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['salida_monto'] = 0;
                }
                if (!isset($lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['salida_cantidad'])) {
                    $lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['salida_cantidad'] = 0;
                }
                if (!isset($lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['ingreso_monto'])) {
                    $lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['ingreso_monto'] = 0;
                }
                if (!isset($lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['ingreso_cantidad'])) {
                    $lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['ingreso_cantidad'] = 0;
                }
                $saldoCantidadInicial = ($value['tipo_documento'] == K::kardex_SaldoInicial) ? $value['saldo_cantidad'] : 0;
                $resumen[$value['codalmacen']][$value['referencia']]['saldo_cantidad'] += ($saldoCantidadInicial + ($value['ingreso_cantidad'] - $value['salida_cantidad']));

                //Primera revisión del Saldo
                $linea_resultado = $value;
                $linea_resultado['saldo_cantidad'] = ($value['tipo_documento'] == K::kardex_SaldoInicial) ? $value['saldo_cantidad'] : $resumen[$value['codalmacen']][$value['referencia']]['saldo_cantidad'];
                
                //Corregimos si hay una regularización de Stock
                if(isset($value['regularizacion_cantidad'])){
                    $cantidadRegularizacion = $linea_resultado['saldo_cantidad']-$value['regularizacion_cantidad'];
                    $value['ingreso_cantidad'] = ($cantidadRegularizacion < 0)?$cantidadRegularizacion*-1:0;
                    $value['salida_cantidad'] = ($cantidadRegularizacion > 0)?($cantidadRegularizacion):0;
                    //Si hubo una regularización actualizamos los valores de la linea
                    $linea_resultado = $value;
                    $resumen[$value['codalmacen']][$value['referencia']]['saldo_cantidad'] += ($saldoCantidadInicial + ($value['ingreso_cantidad'] - $value['salida_cantidad']));
                    $linea_resultado['saldo_cantidad'] = ($value['tipo_documento'] == K::kardex_SaldoInicial) ? $value['saldo_cantidad'] : $resumen[$value['codalmacen']][$value['referencia']]['saldo_cantidad'];
                }
                
                if($this->valorizado){
                    $saldoMontoInicial = ($value['tipo_documento'] == 'Saldo Inicial') ? $value['saldo_monto'] : 0;
                    $resumen[$value['codalmacen']][$value['referencia']]['saldo_monto'] += ($saldoMontoInicial + ($value['ingreso_monto'] - $value['salida_monto']));
                    $linea_resultado['saldo_monto'] = ($value['tipo_documento'] == K::kardex_SaldoInicial) ? $value['saldo_monto'] : $resumen[$value['codalmacen']][$value['referencia']]['saldo_monto'];
                    
                    if(isset($value['regularizacion_monto'])){
                        $montoRegularizacion = $linea_resultado['saldo_monto']-$value['regularizacion_monto'];
                        $linea_resultado['ingreso_monto'] = ($montoRegularizacion > 0)?$montoRegularizacion:0;
                        $linea_resultado['salida_monto'] = ($montoRegularizacion < 0)?($montoRegularizacion*-1):0;
                        $resumen[$value['codalmacen']][$value['referencia']]['saldo_monto'] += ($saldoCantidadInicial + ($value['ingreso_monto'] - $value['salida_monto']));
                        $linea_resultado['saldo_monto'] = ($value['tipo_documento'] == 'Saldo Inicial') ? $value['saldo_monto'] : $resumen[$value['codalmacen']][$value['referencia']]['saldo_monto'];
                    }
                    $lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['ingreso_monto'] += $value['ingreso_monto'];
                    $lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['salida_monto'] += $value['salida_monto'];
                    $lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['saldo_monto'] = $linea_resultado['saldo_monto'];
                }
                
                $this->lista_resultado[] = $linea_resultado;
                $cabecera_export[$value['referencia']] = $value['descripcion'];
                $lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['ingreso_cantidad'] += $value['ingreso_cantidad'];
                $lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['salida_cantidad'] += $value['salida_cantidad'];
                $lista_export[$value['referencia']][$value['fecha']][$value['tipo_documento']][$value['documento']]['saldo_cantidad'] = $linea_resultado['saldo_cantidad'];

            }
        }

        foreach ($lista_export as $referencia => $listafecha) {
            $lineas = 0;
            $sumaSalidasQda[$referencia] = 0;
            $sumaIngresosQda[$referencia] = 0;
            if($this->valorizado){
                $sumaSalidasMonto[$referencia] = 0;
                $sumaIngresosMonto[$referencia] = 0;
            }
            foreach ($listafecha as $fecha => $tipo_documentos) {
                
                foreach ($tipo_documentos as $tipo_documento => $documentos) {
                    foreach ($documentos as $documento => $movimiento) {
                        if ($lineas == 0) {
                            if($this->valorizado){
                                $this->writer->writeSheetRow($almacen->nombre, array('', '', '', '', $cabecera_export[$referencia], '', '', '', '', '', ''),$this->estilo_cabecera);
                            }else{
                                $this->writer->writeSheetRow($almacen->nombre, array('', '', '', '', $cabecera_export[$referencia], '', '', ''),$this->estilo_cabecera);
                            }
                            $this->writer->writeSheetRow($almacen->nombre, $this->header,$this->estilo_cabecera);
                        }
                        $valores= array();
                        $valores[] = $fecha;
                        $valores[] = $tipo_documento;
                        $valores[] = $documento;
                        $valores[] = $referencia;
                        $valores[] = $cabecera_export[$referencia];
                        $valores[] = $movimiento['salida_cantidad'];
                        if($this->valorizado){
                            $valores[] = $movimiento['salida_monto'];
                        }
                        $valores[] = $movimiento['ingreso_cantidad'];
                        if($this->valorizado){
                            $valores[] = $movimiento['ingreso_monto'];
                        }
                            $valores[] = $movimiento['saldo_cantidad'];
                        if($this->valorizado){
                            $valores[] = $movimiento['saldo_monto'];
                        }
                        
                        $this->writer->writeSheetRow($almacen->nombre, $valores);
                        $sumaSalidasQda[$referencia] += $movimiento['salida_cantidad'];
                        $sumaIngresosQda[$referencia] += $movimiento['ingreso_cantidad'];
                        if($this->valorizado){
                            $sumaSalidasMonto[$referencia] += $movimiento['salida_monto'];
                            $sumaIngresosMonto[$referencia] += $movimiento['ingreso_monto'];
                        }
                        $lineas++;
                    }
                }
            }
            if($this->valorizado){
                $this->writer->writeSheetRow(
                        $almacen->nombre, 
                        array('', '', '', $referencia, K::kardex_SaldoFinal, $sumaSalidasQda[$referencia], $sumaSalidasMonto[$referencia], $sumaIngresosQda[$referencia], $sumaIngresosMonto[$referencia], ($saldoInicial[$referencia] + $sumaIngresosQda[$referencia] - $sumaSalidasQda[$referencia]), ($sumaIngresosMonto[$referencia] - $sumaSalidasMonto[$referencia]))
                        ,$this->estilo_pie
                    );
                $this->writer->writeSheetRow($almacen->nombre, array('', '', '', '', '', '', '', '', '', '', ''));
            }else{
                $this->writer->writeSheetRow(
                        $almacen->nombre, 
                        array('', '', '', $referencia, K::kardex_SaldoFinal, $sumaSalidasQda[$referencia], $sumaIngresosQda[$referencia], ($saldoInicial[$referencia] + $sumaIngresosQda[$referencia] - $sumaSalidasQda[$referencia]))
                        ,$this->estilo_pie
                    );
                $this->writer->writeSheetRow($almacen->nombre, array('', '', '', '', '', '', '', ''));
            }
            
        }
        return $this->lista_resultado;
    }

    private function share_extension() {
        $extensiones = array(
            array(
                'name' => 'analisisarticulos_css001',
                'page_from' => __CLASS__,
                'page_to' => 'informe_analisisarticulos',
                'type' => 'head',
                'text' => '<link rel="stylesheet" type="text/css" media="screen" href="' . FS_PATH . 'plugins/kardex/view/css/ui.jqgrid-bootstrap.css"/>',
                'params' => ''
            ),
            array(
                'name' => 'analisisarticulos_css002',
                'page_from' => __CLASS__,
                'page_to' => 'informe_analisisarticulos',
                'type' => 'head',
                'text' => '<link rel="stylesheet" type="text/css" media="screen" href="' . FS_PATH . 'plugins/kardex/view/css/bootstrap-select.min.css"/>',
                'params' => ''
            ),
            array(
                'name' => 'analisisarticulos_css003',
                'page_from' => __CLASS__,
                'page_to' => 'informe_analisisarticulos',
                'type' => 'head',
                'text' => '<link rel="stylesheet" type="text/css" media="screen" href="' . FS_PATH . 'plugins/kardex/view/css/ajax-bootstrap-select.min.css"/>',
                'params' => ''
            ),
        );

        foreach ($extensiones as $ext) {
            $fsext0 = new fs_extension($ext);
            if (!$fsext0->save()) {
                $this->new_error_msg('Imposible guardar los datos de la extensión ' . $ext['name'] . '.');
            }
        }
    }

    private function familia_data() {
        $result = "'";
        foreach ($this->familia as $key => $value) {
            $result .= $value . "','";
        }
        return substr($result, 0, strlen($result) - 2);
    }

    private function articulo_data() {
        $result = "'";
        foreach ($this->articulo as $key => $value) {
            $result .= $value . "','";
        }
        return substr($result, 0, strlen($result) - 2);
    }

    private function language($lang = false) {
        $language = ($lang and file_exists('plugins/kardex/lang/lang_' . $lang . '.ini')) ? $lang : 'es';
        $this->i18n_controller = new i18n_kardex('plugins/kardex/lang/lang_' . $language . '.ini', 'plugins/kardex/langcache/');
        $this->i18n_controller->setForcedLang($language);
        $this->i18n_controller->setPrefix('K');
        $this->i18n_controller->init();
    }

    /**
     * @url http://snippets.khromov.se/convert-comma-separated-values-to-array-in-php/
     * @param $string - Input string to convert to array
     * @param string $separator - Separator to separate by (default: ,)
     *
     * @return array
     */
    private function comma_separated_to_array($string, $separator = ',') {
        //Explode on comma
        $vals = explode($separator, $string);

        //Trim whitespace
        foreach ($vals as $key => $val) {
            $vals[$key] = trim($val);
        }
        //Return empty array if no items found
        //http://php.net/manual/en/function.explode.php#114273
        return array_diff($vals, array(""));
    }

}
