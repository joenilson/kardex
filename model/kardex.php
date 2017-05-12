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
         'kardex_ultimo_proceso' => '',
         'kardex_procesandose' => 'FALSE',
         'kardex_usuario_procesando' => ''
      ));
      $fsvar->array_save($config);
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

   /*
    * Este cron generará los saldos de almacen por día
    * Para que cuando soliciten el movimiento por almacen por
    * articulo se pueda extraer de aquí en forma de resumen histórico
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

      if (!$this->cron) {
         ob_implicit_flush(true);
         ob_end_flush();
      }

      $inicio_total = new DateTime('NOW');
      $contador = 0;
      foreach ($rango as $fecha) {
         $inicio_paso = new DateTime('NOW');
         sleep(1);
         $plural = ($contador == 0) ? "" : "s";
         $p = ceil((($contador + 1) * 100) / $dias_proceso);
         $this->fecha_proceso = $fecha->format('Y-m-d');
         //Bloqueamos el intento de procesar el Kardex por varios usuarios al mismo tiempo
         $fsvar->array_save(
                 array(
                    'kardex_ultimo_proceso' => $this->fecha_proceso,
                    'kardex_procesandose' => 'TRUE',
                    'kardex_usuario_procesando' => ($usuario) ? $usuario : 'cron'
                 )
         );
         $this->kardex_almacen();
         if (!$this->cron) {
            $fin_paso = $inicio_paso->diff(new DateTime('NOW'));
            $tiempo_proceso = $inicio_total->diff(new DateTime('NOW'));
            $time = $fin_paso->h . ':' . $fin_paso->i . ':' . $fin_paso->s;
            $tiempo_en_segundos = strtotime("1970-01-01 $time UTC");
            $tiempo_estimado = gmdate("H:i:s", ($dias_proceso * $tiempo_en_segundos));
            $response = array('message' => K::kardex_Procesando.' <b>' . $fecha->format("Y-m-d") . '</b>, ' . ($contador + 1) . ' '.K::kardex_DiaText . $plural ." ".K::kardex_de.' <b>' . $dias_proceso . '</b> '.K::kardex_Procesado . $plural . ' '.K::kardex_EnText.' ' . $tiempo_proceso->format('%H:%I:%S') . ', '.K::kardex_aLasText.': ' . date("h:i:s", time()) .' '. K::kardex_TiempoEstimado.': <b>' . $tiempo_estimado . '</b>',
               'progress' => $p);
            echo json_encode($response);
         }
         $contador++;
      }
      sleep(1);
      $fsvar->array_save(array(
         'kardex_ultimo_proceso' => $this->fecha_proceso,
         'kardex_procesandose' => 'FALSE',
         'kardex_usuario_procesando' => ''
      ));
      if (!$this->cron) {
         $response = array('message' => '<b>¡'.K::kardex_AlertaCompleto.' '.K::kardex_EnText . $tiempo_proceso->format('%H:%I:%S') . '!<b>',
            'progress' => 100);
         echo json_encode($response);
      } else {
         echo " ** Proceso de Inventario diario concluido...\n";
      }
   }

   /*
    * Generamos la información por cada almacén activo
    */

   public function kardex_almacen() {
      if ($this->fecha_proceso) {
         foreach ($this->almacen->all() as $almacen) {
            $this->stock_query($almacen);
         }
         gc_collect_cycles();
      }
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
                $linea['monto'] = ($linea['coddivisa'] != $this->empresa->coddivisa) ? $this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')) : $linea['monto'];
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


   /*
    * Esta es la consulta multiple que utilizamos para sacar la información
    * de todos los articulos tanto ingresos como salidas
    */
   public function stock_query($almacen) {
      //Generamos el select para la subconsulta de productos activos y que se controla su stock
      $productos = "SELECT referencia, descripcion, costemedio FROM articulos where bloqueado = false and nostock = false;";
      $lista_productos = $this->db->select($productos);
      $resultados = array();
      if ($lista_productos) {
         foreach ($lista_productos as $item) {
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
            $sql_regstocks = "select referencia, descripcion, cantidad_saldo, monto_saldo
                     FROM kardex
                     where codalmacen = '" . $almacen->codalmacen . "' AND fecha = '" . $fechaAnterior . "'
                     and referencia = '" . $item['referencia'] . "';";
            $data = $this->db->select($sql_regstocks);
            if ($data) {
               foreach ($data as $linea) {
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
            $data = $this->db->select($sql_albaranes);
            if ($data) {
               foreach ($data as $linea) {
                  $linea['monto'] = ($linea['coddivisa']!=$this->empresa->coddivisa)?$this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')):$linea['monto'];
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
            $data = $this->db->select($sql_facturasprov);
            if ($data) {
               foreach ($data as $linea) {
                  $linea['monto'] = ($linea['coddivisa']!=$this->empresa->coddivisa)?$this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')):$linea['monto'];
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
            $data = $this->db->select($sql_albaranes);
            if ($data) {
               foreach ($data as $linea) {
                  $linea['monto'] = ($linea['coddivisa']!=$this->empresa->coddivisa)?$this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')):$linea['monto'];
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
             */
            $sql_albaranes = "select ac.idalbaran,referencia,coddivisa,tasaconv,sum(cantidad) as cantidad, sum(pvptotal) as monto
                      from albaranescli as ac
                      join lineasalbaranescli as l ON (ac.idalbaran=l.idalbaran)
                      where codalmacen = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
                      and idfactura is null
                      and referencia = '" . $item['referencia'] . "'
                      group by ac.idalbaran,referencia,coddivisa,tasaconv
                      order by ac.idalbaran;";
            $data = $this->db->select($sql_albaranes);
            if ($data) {
               foreach ($data as $linea) {
                  $linea['monto'] = ($linea['coddivisa']!=$this->empresa->coddivisa)?$this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')):$linea['monto'];
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
            $data = $this->db->select($sql_facturas);
            if ($data) {
               foreach ($data as $linea) {
                  $linea['monto'] = ($linea['coddivisa']!=$this->empresa->coddivisa)?$this->euro_convert($this->divisa_convert($linea['monto'], $linea['coddivisa'], 'EUR')):$linea['monto'];
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
                $sql_regstocks = "select l.idtrans, referencia, sum(cantidad) as cantidad
                from lineastransstock AS ls
                JOIN transstock as l ON(ls.idtrans = l.idtrans)
                where codalmadestino = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
                and referencia = '" . $item['referencia'] . "'
                group by l.idtrans, referencia
                order by l.idtrans;";
                $data = $this->db->select($sql_regstocks);
                if ($data) {
                   foreach ($data as $linea) {
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
                $sql_regstocks = "select l.idtrans, referencia, sum(cantidad) as cantidad
                from lineastransstock AS ls
                JOIN transstock as l ON(ls.idtrans = l.idtrans)
                where codalmaorigen = '" . $almacen->codalmacen . "' AND fecha = '" . $this->fecha_proceso . "'
                and referencia = '" . $item['referencia'] . "'
                group by l.idtrans, referencia
                order by l.idtrans;";
                $data = $this->db->select($sql_regstocks);
                if ($data) {
                   foreach ($data as $linea) {
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
             and referencia = '" . $item['referencia'] . "'
             group by hora, l.idstock, referencia, motivo
             order by l.idstock;";
            $data = $this->db->select($sql_regstocks);
            if ($data) {
               foreach ($data as $linea) {
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
      if($this->empresa->coddivisa == 'EUR')
      {
         return $precio;
      }
      else if($coddivisa AND $tasaconv)
      {
         if($this->empresa->coddivisa == $coddivisa)
         {
            return $precio * $tasaconv;
         }
         else
         {
            $original = $precio * $tasaconv;
            return $this->divisa_convert($original, $coddivisa, $this->empresa->coddivisa);
         }
      }
      else
      {
         return $this->divisa_convert($precio, 'EUR', $this->empresa->coddivisa);
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
