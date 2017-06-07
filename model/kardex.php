<?php

/*
 * Copyright (C) 2016 Joe Nilson <joenilson at gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
require_model('articulo.php');
require_model('almacen.php');
require_model('empresa.php');
require_model('divisa.php');

/**
 * Kardex para manejo de Artículos con inventario inicial e inventario final por fecha
 *
 * @author Joe Nilson <joenilson at gmail.com>
 */
class kardex extends fs_model {

   public $codalmacen;
   public $fecha;
   public $referencia;
   public $descripcion;
   public $cantidad_ingreso;
   public $cantidad_salida;
   public $cantidad_saldo;
   public $monto_ingreso;
   public $monto_salida;
   public $monto_saldo;
   public $fecha_inicio;
   public $fecha_fin;
   public $fecha_proceso;
   public $articulo;
   public $almacen;
   public $articulos;
   public $almacenes;
   public $empresa;
   public $kardex_setup;
   public $cron;
   public $tablas;
   public function __construct($s = FALSE) {
      parent::__construct('kardex', 'plugins/kardex/');
      if ($s) {
         $this->codalmacen = $s['codalmacen'];
         $this->fecha = $s['fecha'];
         $this->referencia = $s['referencia'];
         $this->descripcion = $s['descripcion'];
         $this->cantidad_ingreso = floatval($s['cantidad_ingreso']);
         $this->cantidad_salida = floatval($s['cantidad_salida']);
         $this->cantidad_saldo = floatval($s['cantidad_saldo']);
         $this->monto_ingreso = floatval($s['monto_ingreso']);
         $this->monto_salida = floatval($s['monto_salida']);
         $this->monto_saldo = floatval($s['monto_saldo']);
      } else {
         $this->codalmacen = NULL;
         $this->fecha = NULL;
         $this->referencia = NULL;
         $this->descripcion = NULL;
         $this->cantidad_ingreso = 0;
         $this->cantidad_salida = 0;
         $this->cantidad_saldo = 0;
         $this->monto_ingreso = 0;
         $this->monto_salida = 0;
         $this->monto_saldo = 0;
      }
      $this->fecha_inicio = NULL;
      $this->fecha_fin = NULL;
      $this->fecha_proceso = NULL;
      $this->articulo = new articulo();
      $this->almacen = new almacen();
      $this->empresa = new empresa();
      $this->cron = false;
      $this->tablas = $this->db->list_tables();
   }

   public function install() {
      $fsvar = new fs_var();
      $config = $fsvar->array_get(array(
         'kardex_valorizacion' => 'promedio',
      ));
      $fsvar->array_save($config);
      $fsvar->delete('kardex_ultimo_proceso');
      $fsvar->delete('kardex_procesandose');
      $fsvar->delete('kardex_usuario_procesando');
   }

   public function exists() {
      $sql = "SELECT fecha FROM " . $this->table_name . " WHERE "
              . " codalmacen = " . $this->var2str($this->codalmacen)
              . " AND fecha = " . $this->var2str($this->fecha)
              . " AND referencia = " . $this->var2str($this->referencia) . ";";
      $data = $this->db->select($sql);
      if ($data) {
         return TRUE;
      } else {
         return FALSE;
      }
   }

   public function save() {
      if ($this->exists()) {
         $sql = "UPDATE " . $this->table_name . " SET "
                 . " cantidad_ingreso = " . $this->var2str($this->cantidad_ingreso)
                 . ", cantidad_salida = " . $this->var2str($this->cantidad_salida)
                 . ", cantidad_saldo = " . $this->var2str($this->cantidad_saldo)
                 . ", monto_ingreso = " . $this->var2str($this->monto_ingreso)
                 . ", monto_salida = " . $this->var2str($this->monto_salida)
                 . ", monto_saldo = " . $this->var2str($this->monto_saldo)
                 . "  WHERE "
                 . " fecha = " . $this->var2str($this->fecha)
                 . " and referencia = " . $this->var2str($this->referencia)
                 . " and codalmacen = " . $this->var2str($this->codalmacen) . ";";
         return $this->db->exec($sql);
      } else {
         $sql = "INSERT INTO " . $this->table_name . " (codalmacen,fecha,referencia,descripcion,
            cantidad_ingreso,cantidad_salida,cantidad_saldo,monto_ingreso,monto_salida,monto_saldo) VALUES
                   (" . $this->var2str($this->codalmacen)
                 . "," . $this->var2str($this->fecha)
                 . "," . $this->var2str($this->referencia)
                 . "," . $this->var2str($this->descripcion)
                 . "," . $this->var2str($this->cantidad_ingreso)
                 . "," . $this->var2str($this->cantidad_salida)
                 . "," . $this->var2str($this->cantidad_saldo)
                 . "," . $this->var2str($this->monto_ingreso)
                 . "," . $this->var2str($this->monto_salida)
                 . "," . $this->var2str($this->monto_saldo) . ");";

         if ($this->db->exec($sql)) {
            return TRUE;
         } else {
            return FALSE;
         }
      }
   }

   public function ultimo_proceso() {
      $sql = "SELECT max(fecha) as fecha FROM " . $this->table_name . ";";
      $data = $this->db->select($sql);
      if ($data) {
         return $data[0]['fecha'];
      } else {
         return FALSE;
      }
   }

   public function delete() {
      return '';
   }

   /**
    * Este cron generará los saldos de almacen por día
    * Para que cuando soliciten el movimiento por almacen por
    * articulo se pueda extraer de aquí en forma de resumen histórico
    * @deprecated since version 33
    */

   public function cron_job() {
      $fsvar = new fs_var();
      $this->kardex_setup = $fsvar->array_get(
              array(
         'kardex_ultimo_proceso' => $this->fecha_proceso,
         'kardex_procesandose' => 'FALSE',
         'kardex_usuario_procesando' => 'cron',
         'kardex_cron' => '',
         'kardex_programado' => ''
              ), FALSE
      );
      if ($this->kardex_setup['kardex_procesandose'] !== 'TRUE') {
         if ($this->kardex_setup['kardex_cron'] == 'TRUE') {
            echo " * Se encontro un job para procesar\n";
            $ahora = new DateTime('NOW');
            $horaActual = strtotime($ahora->format('H') . ':00:00');
            $horaProgramada = strtotime($this->kardex_setup['kardex_programado'] . ':00:00');
            if ($horaActual == $horaProgramada) {
               echo " ** Se confirma calculo de Inventario Diario\n";
               $this->cron = true;
               $this->procesar_kardex();
            }else{
               echo " ** No coincide la hora de proceso con la de ejecucion de cron se omite el calculo\n";
            }
         }
      }
   }

   /*
    * Actualizamos la información del Kardex con las fechas de inicio y fin
    */

   public function procesar_kardex($usuario = NULL) {

      $fsvar = new fs_var();
      $this->kardex_setup = $fsvar->array_get(
        array(
            'kardex_ultimo_proceso' => $this->fecha_proceso,
            'kardex_procesandose' => 'FALSE',
            'kardex_usuario_procesando' => 'cron'
        ), FALSE
      );

      if ($this->kardex_setup['kardex_procesandose'] == 'TRUE' AND ( $this->cron)) {
         echo " ** Hay otro proceso calculando Kardex se cancela el proceso cron...\n";
         return false;
      }
      if (is_null($this->fecha_inicio)) {
         $this->ultima_fecha();
      }

      $intervalo = date_diff(date_create($this->fecha_inicio), date_create($this->fecha_fin));
      $dias_proceso = $intervalo->format('%a') + 1;
      $rango = $this->rango_fechas();
      /**
       * @deprecated since version 33
      if (!$this->cron) {
         ob_implicit_flush(true);
         ob_end_flush();
      }
      */
      $this->kardex_almacen();
   }

   /*
    * Generamos la información por cada almacén activo
    */

   public function kardex_almacen() {
        foreach ($this->almacen->all() as $almacen) {
           $this->stock_query($almacen);
        }
        gc_collect_cycles();
   }

    /**
     * Con esta funcion se genera la información para el Inventario de Artículos
     * @param type $almacen object \FacturaScripts\model\core\almacen
     * @param type $data array
     * @param type $documento string
     * @param type $tipo string
     */
    public function procesar_informacion(&$array,$almacen,$data,$documento,$tipo){
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
                $linea['monto'] = ($linea['coddivisa'] != $this->coddivisa) ? $this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')) : $linea['monto'];
                if($tipo=='ingreso'){
                    $resultados[$linea['documento']]['salida_monto'] = ($linea['monto'] <= 0) ? ($linea['monto']*-1) : 0;
                    $resultados[$linea['documento']]['ingreso_monto'] = ($linea['monto'] >= 0) ? $linea['monto'] : 0;
                }elseif($tipo=='salida'){
                    $resultados[$linea['documento']]['salida_monto'] = ($linea['monto'] >= 0) ? $linea['monto'] : 0;
                    $resultados[$linea['documento']]['ingreso_monto'] = ($linea['monto'] <= 0) ? ($linea['monto']*-1) : 0;
                }
            }
            $resultados[$linea['documento']]['codalmacen'] = $linea['codalmacen'];
            $resultados[$linea['documento']]['nombre'] = $almacen->nombre;
            $resultados[$linea['documento']]['fecha'] = $linea['fecha'];
            $resultados[$linea['documento']]['tipo_documento'] = $documento. " ".$linea['codigo'];
            $resultados[$linea['documento']]['documento'] = $linea['documento'];
            $resultados[$linea['documento']]['referencia'] = $linea['referencia'];
            $resultados[$linea['documento']]['descripcion'] = stripcslashes($linea['descripcion']);
            if($tipo=='ingreso'){
                $resultados[$linea['documento']]['salida_cantidad'] = ($linea['cantidad'] <= 0) ? $linea['cantidad'] : 0;
                $resultados[$linea['documento']]['ingreso_cantidad'] = ($linea['cantidad'] >= 0) ? $linea['cantidad'] : 0;
            }elseif($tipo=='salida'){
                $resultados[$linea['documento']]['salida_cantidad'] = ($linea['cantidad'] >= 0) ? $linea['cantidad'] : 0;
                $resultados[$linea['documento']]['ingreso_cantidad'] = ($linea['cantidad'] <= 0) ? $linea['cantidad'] : 0;
            }

            $this->lista[$idlinea][] = $resultados[$linea['documento']];
            $this->total_resultados++;
        }
    }

    /**
     * Recalculo de saldos de stock por cada articulo almacén
     * @param type $ref
     * @param type $almacen
     * @return type value
     */
    public function saldo_articulo($ref,$almacen,$desde)
    {
        $total_ingresos = 0;
        //Facturas de compra sin albaran
        $sql_compras1 = "SELECT sum(cantidad) as total FROM lineasfacturasprov as lfp".
                " JOIN facturasprov as fp on (fp.idfactura = lfp.idfactura)".
                " WHERE anulada = FALSE and idalbaran IS NULL and fecha < ".$this->var2str(\date('Y-m-d',strtotime($desde))).
                " AND codalmacen = ".$this->var2str($almacen).
                " AND referencia = ".$this->var2str($ref);
        $data_Compras1 = $this->db->select($sql_compras1);
        if($data_Compras1)
        {
            $total_ingresos += $data_Compras1[0]['total'];
        }
        
        //Albaranes de compra
        $sql_compras2 = "SELECT sum(cantidad) as total FROM lineasalbaranesprov as lap".
                " JOIN albaranesprov as ap on (ap.idalbaran = lap.idalbaran)".
                " WHERE fecha < ".$this->var2str(\date('Y-m-d',strtotime($desde))).
                " AND codalmacen = ".$this->var2str($almacen).
                " AND referencia = ".$this->var2str($ref);
        $data_Compras2 = $this->db->select($sql_compras2);
        if($data_Compras2)
        {
            $total_ingresos += $data_Compras2[0]['total'];
        }
        
        $total_salidas = 0;
        //Facturas de venta sin albaran
        $sql_ventas1 = "SELECT sum(cantidad) as total FROM lineasfacturascli as lfc".
                " JOIN facturascli as fc on (fc.idfactura = lfc.idfactura)".
                " WHERE anulada = FALSE and idalbaran IS NULL and fecha < ".$this->var2str(\date('Y-m-d',strtotime($desde))).
                " AND codalmacen = ".$this->var2str($almacen).
                " AND referencia = ".$this->var2str($ref);
        $data_Ventas1 = $this->db->select($sql_ventas1);
        if($data_Ventas1)
        {
            $total_salidas += $data_Ventas1[0]['total'];
        }
        
        //Albaranes de venta
        $sql_ventas2 = "SELECT sum(cantidad) as total FROM lineasalbaranescli as lac".
                " JOIN albaranescli as ac on (ac.idalbaran = lac.idalbaran)".
                " WHERE fecha < ".$this->var2str(\date('Y-m-d',strtotime($desde))).
                " AND codalmacen = ".$this->var2str($almacen).
                " AND referencia = ".$this->var2str($ref);
        $data_Ventas2 = $this->db->select($sql_ventas2);
        if($data_Ventas2)
        {
            $total_salidas += $data_Ventas2[0]['total'];
        }
        
        //Si existen estas tablas se genera la información de las transferencias de stock
        if ($this->db->table_exists('transstock', $this->tablas) AND $this->db->table_exists('lineastransstock', $this->tablas)) {
            /*
             * Generamos la informacion de las transferencias por ingresos entre almacenes que se hayan hecho a los stocks
             */
            $sql_transstock1 = "select sum(cantidad) as total FROM lineastransstock AS ls".
            " JOIN transstock as l ON(ls.idtrans = l.idtrans) ".
            " WHERE codalmadestino = ".$this->var2str($almacen). 
            " AND fecha < ".$this->var2str(\date('Y-m-d',strtotime($desde))).
            " AND referencia = ".$this->var2str($ref);
            $data_transstock1 = $this->db->select($sql_transstock1);
            if ($data_transstock1) {
                $total_ingresos += $data_transstock1[0]['total'];
            }

            /*
             * Generamos la informacion de las transferencias por salidas entre almacenes que se hayan hecho a los stocks
             */
            $sql_transstock2 = "select sum(cantidad) as total FROM lineastransstock AS ls ".
            " JOIN transstock as l ON(ls.idtrans = l.idtrans) ".
            " WHERE  codalmaorigen = ".$this->var2str($almacen).
            " AND fecha < ".$this->var2str(\date('Y-m-d',strtotime($desde))).
            " AND referencia = ".$this->var2str($ref);
            $data_transstock2 = $this->db->select($sql_transstock2);
            if ($data_transstock2) {
                $total_salidas += $data_transstock2[0]['total'];
            }
        }
        
        //Si existe esta tabla se genera la información de las regularizaciones de stock y se agrega como salida el resultado
        if ($this->db->table_exists('lineasregstocks', $this->tablas)) {
            $sql_regstocks = "select sum(cantidadini-cantidadfin) as total from lineasregstocks AS ls ".
            " JOIN stocks as l ON(ls.idstock = l.idstock) ".
            " WHERE fecha < ".$this->var2str(\date('Y-m-d',strtotime($desde))).
            " AND codalmacen = ".$this->var2str($almacen).
            " AND referencia = ".$this->var2str($ref);
            $data_regstocks = $this->db->select($sql_regstocks);
            if ($data_regstocks) {
                $cantidad = $data_regstocks[0]['total'];
                $total_salidas += $cantidad;
            }
        }
        
        $total_saldo = $total_ingresos - $total_salidas;
        return $total_saldo;
    }
    
   /*
    * Esta es la consulta multiple que utilizamos para sacar la información
    * de todos los articulos tanto ingresos como salidas
    */
   public function stock_query($almacen) {
      //Generamos el select para la subconsulta de productos activos y que se controla su stock
      $productos = "SELECT referencia, descripcion, costemedio FROM articulos where bloqueado = false and nostock = false;";
      $lista_productos = $this->db->select($productos);
      
      if ($lista_productos) {
         foreach ($lista_productos as $item) {
            $resultados = array();
            $resultados['kardex']['referencia'] = $item['referencia'];
            $resultados['kardex']['descripcion'] = stripcslashes($item['descripcion']);
            $resultados['kardex']['salida_cantidad'] = 0;
            $resultados['kardex']['ingreso_cantidad'] = 0;
            $resultados['kardex']['salida_monto'] = 0;
            $resultados['kardex']['ingreso_monto'] = 0;
            $resultados['kardex']['cantidad_inicial'] = 0;
            $resultados['kardex']['monto_inicial'] = 0;

            /*
             * Generamos la informacion del saldo final del dia anterior segun Inventario diario
             */
            $fechaProceso = new DateTime($this->fecha_proceso);
            $fechaAnterior = $fechaProceso->sub(new DateInterval('P1D'))->format('Y-m-d');
            $sql_saldo_anterior = "select referencia, descripcion, cantidad_saldo, monto_saldo
                     FROM kardex
                     where codalmacen = '" . $almacen->codalmacen . "' AND fecha = '" . $fechaAnterior . "'
                     and referencia = '" . $item['referencia'] . "';";
            $data_saldo_anterior = $this->db->select($sql_saldo_anterior);
            if ($data_saldo_anterior) {
               foreach ($data_saldo_anterior as $linea) {
                  $resultados['kardex']['referencia'] = $item['referencia'];
                  $resultados['kardex']['descripcion'] = $item['descripcion'];
                  $resultados['kardex']['cantidad_inicial'] = $linea['cantidad_saldo'];
                  $resultados['kardex']['monto_inicial'] = $linea['monto_saldo'];
               }
            }

            /*
             * Generamos la informacion de los albaranes de proveedor asociados a facturas no anuladas
             */
            $sql_albaranes = "select ac.idalbaran,referencia,coddivisa,tasaconv,sum(cantidad) as cantidad, sum(pvptotal) as monto
            from albaranesprov as ac
            join lineasalbaranesprov as l ON (ac.idalbaran=l.idalbaran)
            where codalmacen = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
            and idfactura is not null
            and referencia = '" . $item['referencia'] . "'
            group by ac.idalbaran,l.referencia,coddivisa,tasaconv
            order by ac.idalbaran;";
            $data1 = $this->db->select($sql_albaranes);
            if ($data1) {
               foreach ($data1 as $linea) {
                  $linea['monto'] = ($linea['coddivisa']!=$this->coddivisa)?$this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')):$linea['monto'];
                  $resultados['kardex']['referencia'] = $item['referencia'];
                  $resultados['kardex']['descripcion'] = $item['descripcion'];
                  $resultados['kardex']['salida_cantidad'] += ($linea['cantidad'] <= 0) ? ($linea['cantidad'] * -1) : 0;
                  $resultados['kardex']['ingreso_cantidad'] += ($linea['cantidad'] >= 0) ? $linea['cantidad'] : 0;
                  $resultados['kardex']['salida_monto'] += ($linea['monto'] <= 0) ? ($linea['monto'] * -1) : 0;
                  $resultados['kardex']['ingreso_monto'] += ($linea['monto'] >= 0) ? $linea['monto'] : 0;
               }
            }
            
            /*
             * Generamos la informacion de los albaranes de proveedor no asociados a facturas
             */
            $sql_albaranes = "select ac.idalbaran,referencia,coddivisa,tasaconv,sum(cantidad) as cantidad, sum(pvptotal) as monto
            from albaranesprov as ac
            join lineasalbaranesprov as l ON (ac.idalbaran=l.idalbaran)
            where codalmacen = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
            and idfactura is null
            and referencia = '" . $item['referencia'] . "'
            group by ac.idalbaran,l.referencia,coddivisa,tasaconv 
            order by ac.idalbaran;";
            $data2 = $this->db->select($sql_albaranes);
            if ($data2) {
               foreach ($data2 as $linea) {
                  $linea['monto'] = ($linea['coddivisa']!=$this->coddivisa)?$this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')):$linea['monto'];
                  $resultados['kardex']['referencia'] = $item['referencia'];
                  $resultados['kardex']['descripcion'] = $item['descripcion'];
                  $resultados['kardex']['salida_cantidad'] += ($linea['cantidad'] <= 0) ? ($linea['cantidad'] * -1) : 0;
                  $resultados['kardex']['ingreso_cantidad'] += ($linea['cantidad'] >= 0) ? $linea['cantidad'] : 0;
                  $resultados['kardex']['salida_monto'] += ($linea['monto'] <= 0) ? ($linea['monto'] * -1) : 0;
                  $resultados['kardex']['ingreso_monto'] += ($linea['monto'] >= 0) ? $linea['monto'] : 0;
               }
            }

            /*
             * Generamos la informacion de las facturas de proveedor ingresadas
             * que no esten asociadas a un albaran de proveedor
             */
            $sql_facturasprov = "select fc.idfactura,referencia,coddivisa,tasaconv,sum(cantidad) as cantidad, sum(pvptotal) as monto
            from facturasprov as fc
            join lineasfacturasprov as l ON (fc.idfactura=l.idfactura)
            where codalmacen = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
            and anulada=FALSE and idalbaran is null
            and referencia = '" . $item['referencia'] . "'
            group by fc.idfactura,referencia,coddivisa,tasaconv
            order by fc.idfactura;";
            $data3 = $this->db->select($sql_facturasprov);
            if ($data3) {
               foreach ($data3 as $linea) {
                  $linea['monto'] = ($linea['coddivisa']!=$this->coddivisa)?$this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')):$linea['monto'];
                  $resultados['kardex']['referencia'] = $item['referencia'];
                  $resultados['kardex']['descripcion'] = $item['descripcion'];
                  $resultados['kardex']['salida_cantidad'] += ($linea['cantidad'] <= 0) ? ($linea['cantidad'] * -1) : 0;
                  $resultados['kardex']['ingreso_cantidad'] += ($linea['cantidad'] >= 0) ? $linea['cantidad'] : 0;
                  $resultados['kardex']['salida_monto'] += ($linea['monto'] <= 0) ? ($linea['monto'] * -1) : 0;
                  $resultados['kardex']['ingreso_monto'] += ($linea['monto'] >= 0) ? $linea['monto'] : 0;
               }
            }

            /*
             * Generamos la informacion de los albaranes asociados a facturas no anuladas
             */
            $sql_albaranes = "select ac.idalbaran,referencia,coddivisa,tasaconv,sum(cantidad) as cantidad, sum(pvptotal) as monto
                      from albaranescli as ac
                      join lineasalbaranescli as l ON (ac.idalbaran=l.idalbaran)
                      where codalmacen = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
                      and idfactura is not null
                      and referencia = '" . $item['referencia'] . "'
                      group by ac.idalbaran,referencia,coddivisa,tasaconv
                      order by ac.idalbaran;";
            $data4 = $this->db->select($sql_albaranes);
            if ($data4) {
               foreach ($data4 as $linea) {
                  $linea['monto'] = ($linea['coddivisa']!=$this->coddivisa)?$this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')):$linea['monto'];
                  $resultados['kardex']['referencia'] = $item['referencia'];
                  $resultados['kardex']['descripcion'] = $item['descripcion'];
                  $resultados['kardex']['salida_cantidad'] += ($linea['cantidad'] >= 0) ? $linea['cantidad'] : 0;
                  $resultados['kardex']['ingreso_cantidad'] += ($linea['cantidad'] <= 0) ? ($linea['cantidad'] * -1) : 0;
                  $resultados['kardex']['salida_monto'] += ($linea['monto'] >= 0) ? $linea['monto'] : 0;
                  $resultados['kardex']['ingreso_monto'] += ($linea['monto'] <= 0) ? ($linea['monto'] * -1) : 0;
               }
            }

            /*
             * Generamos la informacion de los albaranes no asociados a facturas
             * Asi sea una salida en negativo la colocamos como salida para que netee las salidas
             */
            $sql_albaranes = "select ac.idalbaran,referencia,coddivisa,tasaconv,sum(cantidad) as cantidad, sum(pvptotal) as monto
                      from albaranescli as ac
                      join lineasalbaranescli as l ON (ac.idalbaran=l.idalbaran)
                      where codalmacen = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
                      and idfactura is null
                      and referencia = '" . $item['referencia'] . "'
                      group by ac.idalbaran,referencia,coddivisa,tasaconv
                      order by ac.idalbaran;";
            $data5 = $this->db->select($sql_albaranes);
            if ($data5) {
               foreach ($data5 as $linea) {
                  $linea['monto'] = ($linea['coddivisa']!=$this->coddivisa)?$this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')):$linea['monto'];
                  $resultados['kardex']['referencia'] = $item['referencia'];
                  $resultados['kardex']['descripcion'] = $item['descripcion'];
                  $resultados['kardex']['salida_cantidad'] += ($linea['cantidad'] >= 0) ? $linea['cantidad'] : 0;
                  $resultados['kardex']['ingreso_cantidad'] += ($linea['cantidad'] <= 0) ? ($linea['cantidad'] * -1) : 0;
                  $resultados['kardex']['salida_monto'] += ($linea['monto'] >= 0) ? $linea['monto'] : 0;
                  $resultados['kardex']['ingreso_monto'] += ($linea['monto'] <= 0) ? ($linea['monto'] * -1) : 0;
               }
            }

            /*
             * Generamos la informacion de las facturas que se han generado sin albaran
             */
            $sql_facturas = "select fc.idfactura,referencia,coddivisa,tasaconv ,sum(cantidad) as cantidad, sum(pvptotal) as monto
                from facturascli as fc
                join lineasfacturascli as l ON (fc.idfactura=l.idfactura)
                where codalmacen = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
                and anulada=FALSE and idalbaran is null
                and referencia = '" . $item['referencia'] . "'
                group by fc.idfactura,referencia,coddivisa,tasaconv
                order by fc.idfactura;";
            $data6 = $this->db->select($sql_facturas);
            if ($data6) {
               foreach ($data6 as $linea) {
                  $linea['monto'] = ($linea['coddivisa']!=$this->coddivisa)?$this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')):$linea['monto'];
                  $resultados['kardex']['referencia'] = $item['referencia'];
                  $resultados['kardex']['descripcion'] = $item['descripcion'];
                  $resultados['kardex']['salida_cantidad'] += ($linea['cantidad'] >= 0) ? $linea['cantidad'] : 0;
                  $resultados['kardex']['ingreso_cantidad'] += ($linea['cantidad'] <= 0) ? ($linea['cantidad'] * -1) : 0;
                  $resultados['kardex']['salida_monto'] += ($linea['monto'] >= 0) ? $linea['monto'] : 0;
                  $resultados['kardex']['ingreso_monto'] += ($linea['monto'] <= 0) ? ($linea['monto'] * -1) : 0;
               }
            }

            //Si existen estas tablas se genera la información de las transferencias de stock
            if( $this->db->table_exists('transstock', $this->tablas) AND $this->db->table_exists('lineastransstock', $this->tablas) ){
                /*
                 * Generamos la informacion de las transferencias por ingresos entre almacenes que se hayan hecho a los stocks
                 */
                $sql_transstock1 = "select l.idtrans, referencia, sum(cantidad) as cantidad
                from lineastransstock AS ls
                JOIN transstock as l ON(ls.idtrans = l.idtrans)
                where codalmadestino = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
                and referencia = '" . $item['referencia'] . "'
                group by l.idtrans, referencia
                order by l.idtrans;";
                $data7 = $this->db->select($sql_transstock1);
                if ($data7) {
                   foreach ($data7 as $linea) {
                      $resultados['kardex']['referencia'] = $item['referencia'];
                      $resultados['kardex']['descripcion'] = $item['descripcion'];
                      $resultados['kardex']['salida_cantidad'] += 0;
                      $resultados['kardex']['ingreso_cantidad'] += ($linea['cantidad'] >= 0) ? $linea['cantidad'] : 0;
                      $resultados['kardex']['salida_monto'] += 0;
                      $resultados['kardex']['ingreso_monto'] += ($linea['cantidad'] >= 0) ? ($item['costemedio'] * $linea['cantidad']) : 0;
                   }
                }

                /*
                 * Generamos la informacion de las transferencias por salidas entre almacenes que se hayan hecho a los stocks
                 */
                $sql_transstock2 = "select l.idtrans, referencia, sum(cantidad) as cantidad
                from lineastransstock AS ls
                JOIN transstock as l ON(ls.idtrans = l.idtrans)
                where codalmaorigen = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
                and referencia = '" . $item['referencia'] . "'
                group by l.idtrans, referencia
                order by l.idtrans;";
                $data8 = $this->db->select($sql_transstock2);
                if ($data8) {
                   foreach ($data8 as $linea) {
                      $resultados['kardex']['referencia'] = $item['referencia'];
                      $resultados['kardex']['descripcion'] = $item['descripcion'];
                      $resultados['kardex']['salida_cantidad'] += ($linea['cantidad'] >= 0) ? $linea['cantidad'] : 0;
                      $resultados['kardex']['ingreso_cantidad'] += 0;
                      $resultados['kardex']['salida_monto'] += ($linea['cantidad'] >= 0) ? ($item['costemedio'] * $linea['cantidad']) : 0;
                      $resultados['kardex']['ingreso_monto'] += 0;
                   }
                }
            }

            /*
             * Generamos la informacion de las regularizaciones que se hayan hecho a los stocks
             */
            $sql_regstocks = "select l.idstock, referencia, motivo, sum(cantidadfin) as cantidad
             from lineasregstocks AS ls
             JOIN stocks as l ON(ls.idstock = l.idstock)
             where codalmacen = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
             and codalmacen = codalmacendest 
             and referencia = '" . $item['referencia'] . "'
             group by hora, l.idstock, referencia, motivo
             order by l.idstock;";
            $data9 = $this->db->select($sql_regstocks);
            
            if ($data9) {
               foreach ($data9 as $linea) {
                  $resultados['kardex']['referencia'] = $item['referencia'];
                  $resultados['kardex']['descripcion'] = $item['descripcion'];
                  $resultados['kardex']['regularizacion_cantidad'] = $linea['cantidad'];
                  $resultados['kardex']['regularizacion_monto'] = ($item['costemedio'] * $linea['cantidad']);
                  $resultados['kardex']['salida_cantidad'] = 0;
                  $resultados['kardex']['ingreso_cantidad'] = 0;
                  $resultados['kardex']['salida_monto'] = 0;
                  $resultados['kardex']['ingreso_monto'] = 0;
               }
            }

            /*
             * Guardamos el resultado de las consultas
             */
            foreach ($resultados as $valores) {
               $valores['ingreso_cantidad'] = ($valores['ingreso_cantidad']) ? $valores['ingreso_cantidad'] : 0;
               $valores['salida_cantidad'] = ($valores['salida_cantidad']) ? $valores['salida_cantidad'] : 0;
               $valores['ingreso_monto'] = ($valores['ingreso_monto']) ? $valores['ingreso_monto'] : 0;
               $valores['salida_monto'] = ($valores['salida_monto']) ? $valores['salida_monto'] : 0;
               $kardex0 = new kardex();
               $kardex0->codalmacen = $almacen->codalmacen;
               $kardex0->fecha = $this->fecha_proceso;
               $kardex0->referencia = $valores['referencia'];
               $kardex0->descripcion = $valores['descripcion'];
               $kardex0->cantidad_ingreso = $valores['ingreso_cantidad'];
               $kardex0->cantidad_salida = $valores['salida_cantidad'];
               $kardex0->cantidad_saldo = ($valores['cantidad_inicial'] + ($valores['ingreso_cantidad'] - $valores['salida_cantidad']));
               $kardex0->monto_ingreso = $valores['ingreso_monto'];
               $kardex0->monto_salida = $valores['salida_monto'];
               $kardex0->monto_saldo = ($valores['monto_inicial'] + ($valores['ingreso_monto'] - $valores['salida_monto']));
               //Cuando se tiene regularizaciones hacemos el cuadre que confirma que este stock está saliendo o entrando como regularización
               if(isset($valores['regularizacion_cantidad'])){
                    $cantidadRegularizacion = $kardex0->cantidad_saldo-$valores['regularizacion_cantidad'];
                    $kardex0->cantidad_ingreso = ($cantidadRegularizacion < 0)?$cantidadRegularizacion*-1:0;
                    $kardex0->cantidad_salida = ($cantidadRegularizacion > 0)?($cantidadRegularizacion):0;
                    $kardex0->cantidad_saldo = ($valores['cantidad_inicial'] + ($kardex0->cantidad_ingreso - $kardex0->cantidad_salida));
                    $montoRegularizacion = $kardex0->monto_saldo-$valores['regularizacion_monto'];
                    $kardex0->monto_ingreso = ($montoRegularizacion > 0)?$montoRegularizacion:0;
                    $kardex0->monto_salida = ($montoRegularizacion < 0)?($montoRegularizacion*-1):0;
                    $kardex0->monto_saldo = ($valores['monto_inicial'] + ($kardex0->monto_ingreso - $kardex0->monto_salida));
                }
                //Guardarmos el resultado
                $kardex0->save();
            }
            gc_collect_cycles();
         }
      }
   }

   public function saldo($fecha, $almacen, $articulos = null) {
      $resultados = array();
      $fechaProceso = new DateTime($fecha);
      $fechaAnterior = $fechaProceso->sub(new DateInterval('P1D'))->format('Y-m-d');
      $sql_regstocks = "select referencia, descripcion, cantidad_saldo, monto_saldo
             FROM kardex
             where codalmacen = '" .$almacen. "' AND fecha = '" .$fechaAnterior. "' $articulos;";
      echo $fechaAnterior;
      $data = $this->db->select($sql_regstocks);
      if ($data) {
         foreach ($data as $linea) {
            $resultados[$linea['referencia']]['referencia'] = $linea['referencia'];
            $resultados[$linea['referencia']]['descripcion'] = $linea['descripcion'];
            $resultados[$linea['referencia']]['cantidad_inicial'] = $linea['cantidad_saldo'];
            $resultados[$linea['referencia']]['monto_inicial'] = $linea['monto_saldo'];
         }
         return $resultados;
      }else{
         return false;
      }
   }

   /*
    * Buscamos la fecha del ultimo ingreso en el Kardex
    */
   public function ultima_fecha() {
      // Buscamos el registro más antiguo en la tabla de kardex
      $min_fecha = $this->db->select("SELECT max(fecha) as fecha FROM kardex;");
      // Si hay data, continuamos desde la siguiente fecha
      if ($min_fecha[0]['fecha']) {
         $min_fecha_inicio0 = new DateTime($min_fecha[0]['fecha']);
         $min_fecha_inicio1 = $min_fecha_inicio0->modify('+1 day');
         $min_fecha_inicio = $min_fecha_inicio1->format('Y-m-d');
      }
      //Si no hay nada tenemos que ejecutar un proceso para todas las fechas desde el registro más antiguo
      else {
         $select = "SELECT min(fecha) as fecha FROM ( ".
            " SELECT min(fecha) AS fecha FROM lineasregstocks ".
            " UNION ".
            " SELECT min(fecha) AS fecha FROM albaranesprov ".
            " UNION ".
            " SELECT min(fecha) AS fecha FROM albaranescli ".
            " UNION ".
            " SELECT min(fecha) AS fecha FROM facturasprov ".
            " UNION ".
            " SELECT min(fecha) AS fecha FROM facturascli ".
            " ) AS t1;";
         $min_fecha = $this->db->select($select);
         $min_fecha_inicio0 = new DateTime($min_fecha[0]['fecha']);
         $min_fecha_inicio = $min_fecha_inicio0->format('Y-m-d');
      }
      $this->fecha_inicio = $min_fecha_inicio;
   }

   /**
    * Generamos fecha de inicio y fecha de fin
    */
   public function rango_fechas() {
      $begin = new DateTime($this->fecha_inicio);
      $end = new DateTime($this->fecha_fin);
      $end->modify("+1 day");
      $interval = new DateInterval('P1D');
      $daterange = new DatePeriod($begin, $interval, $end);
      return $daterange;
   }

   public function euro_convert($precio, $coddivisa = NULL, $tasaconv = NULL)
   {
      if($this->coddivisa == 'EUR')
      {
         return $precio;
      }
      else if($coddivisa AND $tasaconv)
      {
         if($this->coddivisa == $coddivisa)
         {
            return $precio * $tasaconv;
         }
         else
         {
            $original = $precio * $tasaconv;
            return $this->divisa_convert($original, $coddivisa, $this->coddivisa);
         }
      }
      else
      {
         return $this->divisa_convert($precio, 'EUR', $this->coddivisa);
      }
   }

   /**
    * Convierte un precio de la divisa_desde a la divisa especificada
    * @param type $precio
    * @param type $coddivisa_desde
    * @param type $coddivisa
    * @return type
    */
   public function divisa_convert($precio, $coddivisa_desde, $coddivisa)
   {
      if($coddivisa_desde != $coddivisa)
      {
         $div0 = new divisa();
         $divisa_desde = $div0->get($coddivisa_desde);
         if($divisa_desde)
         {
            $divisa = $div0->get($coddivisa);
            if($divisa)
            {
               $precio = $precio / $divisa_desde->tasaconv * $divisa->tasaconv;
            }
         }
      }

      return $precio;
   }

}
