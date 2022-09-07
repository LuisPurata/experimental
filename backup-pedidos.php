<?php
header('Content-Type: application/json');
require_once 'mandarNotificacion.php';
require_once 'pdo.php';
require_once 'sae.php';
require_once '../portal/inc/kmail.class.php';

$sucursal = (isset($_COOKIE['sucursal'])?$_COOKIE['sucursal']:"");
$almacen = (isset($_COOKIE['almacen'])?$_COOKIE['almacen']:"");
$codNoEmpleado = (isset($_COOKIE['codNoEmpleado'])?$_COOKIE['codNoEmpleado']:"");
$tipoUsuario = (isset($_COOKIE['tipoUsuario'])?$_COOKIE['tipoUsuario']:"");
$nombre = (isset($_COOKIE['nombre'])?$_COOKIE['nombre']:"");
$usuario = (isset($_COOKIE['usuario'])?$_COOKIE['usuario']:"");
$mes = date("m");
$periodo = date("Y"); // año
$fecha = date("Y-m-d");

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    try {
        if(isset($_GET['cargos'])){
            $cargos = $sae->query("SELECT CLAVE,camplib2 AS NOMBRE, camplib3 AS PROYECTO FROM CLIE01
                INNER join clie_clib01 on cve_clie = clave
                WHERE STATUS = 'A' 
                    AND (CLAVE NOT LIKE '%999' AND CLAVE NOT LIKE '%000')
                    AND (CLAVE NOT LIKE 'E%' AND CLAVE NOT LIKE 'F%' AND CLAVE NOT LIKE 'G%')
                ORDER BY CLAVE")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cargos as &$c) {
                $c['CLAVE'] = utf8_encode($c['CLAVE']);
                $c['NOMBRE'] = utf8_encode($c['NOMBRE']);
                $c['PROYECTO'] = utf8_encode($c['PROYECTO']);
            }
            http_response_code(200); 
            echo json_encode(["cargos" =>$cargos]);
            exit;
        }

        if(isset($_GET['traer_folios'])){
            $folios = $pdo->query("SELECT f.idPedido, f.folio, f.fechaSurte, f.surtidor, 
                f.recibe, f.fechaEntrega, f.path_firma_receptor, f.path_firma_surtidor
                FROM folios f
                inner join pedidos p on p.idPedido = f.idPedido 
            where p.sucursal = $sucursal")->fetchAll(PDO::FETCH_ASSOC);
            http_response_code(200); //Corregir Multisucursal
            echo json_encode(["folios" =>$folios]);
            exit;
        }

        if(isset($_GET['herramientas_pendientes'])){
            $tipo = isset($_GET['tipo']) ? "1":"2,3";

            $herramientas = $pdo->query("SELECT idPedido, tipoPedido, date(fechaRequerida) as fechaRequerida, 
                date(fechaDevoluciones) as fechaDevoluciones, 
                (SELECT concat(nombre, ' ', apellidoPat, ' ', apellidoMat) 
                FROM sistemanomina_prueba.datospersonalesempleado 
                where idEmpleado = idSolicitante) as solicitadoPor,
                (SELECT concat(nombre, ' ', apellidoPat, ' ', apellidoMat)
                FROM sistemanomina_prueba.datospersonalesempleado
            where idEmpleado = idSupervisor) as AutorizadoPor 
            FROM pedidosalmacen.pedidos where status =  1 and tipoPedido in(2,3,6)  and sucursal=$sucursal")->fetchAll(PDO::FETCH_ASSOC);;
            http_response_code(200);
            echo json_encode(["herramientas" =>$herramientas]);
            exit;
        }

        if(isset($_GET['notificaciones_fecha_requerida'])){
            if($tipoUsuario!=4){
                $solicitante_sql = "p.idSolicitante = '$codEmpleado' and";
                $fecha_sql = "and date(now()) > date(fechaRequerida)";
            }else{
                $solicitante_sql = "";
                $fecha_sql = "and date(now()) > date_add(date(fechaRequerida) ,interval 130 day)";
            }

            $notificaciones = $pdo->query("SELECT idPedido, csp.descripcion,
                DATEDIFF(NOW(),date(p.fechaRequerida)) as diasVencidos
                FROM pedidosalmacen.pedidos p
                inner join pedidosalmacen.cat_status_pedido csp on csp.idStatus = p.status 
                where $solicitante_sql 
                p.status not in (9,10)
                $fecha_sql
                and sucursal = $sucursal")->fetchAll(PDO::FETCH_ASSOC);
            http_response_code(200);
            echo json_encode(["notificaciones" =>$notificaciones]);
            exit;
        }

        if(isset($_GET['prestamo_hta_correo_supervisores'])){
            $supervisores = $pdo->query("SELECT p.idSupervisor, 
            group_concat(distinct ap.CVE_ART) as Articulos, nombre, correo
                from pedidos p
                inner join articulos_pedidos ap on ap.idPedido = p.idPedido
                left join (select codNoEmpleado, nombre, correo  from (
                            select u.codNoEmpleado, concat(nombre, ' ', apellidoPat, ' ', apellidoMat) as nombre, u.correo
                        from sistemanomina_prueba.datospersonalesempleado dpe 
                        inner join usuarios u on u.codNoEmpleado=dpe.idEmpleado
                        ) p group by codNoEmpleado) super on super.codNoEmpleado = p.idSupervisor 
                where p.fechaDevoluciones!=0 and DATE_ADD(date(p.fechaDevoluciones),INTERVAL 30 DAY)  <=  date(now())
                and p.tipoPedido in (2,3,6) and ap.CVE_ART  like '30%' 
                group by p.idSupervisor ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($supervisores as $index => $supervisor) {
                $articulos = explode(",", $supervisor["Articulos"]);
                $descripciones = [];
                foreach($articulos as $y_index => $articulo){
                    $descripciones[$y_index]= $pdo->query("SELECT i.DESCR  from  sae.inve01 i  where CVE_ART = '$articulos[$y_index]'")->fetch(PDO::FETCH_COLUMN);
                }
                correo_hta_supervisor($supervisor["nombre"], $supervisor["correo"], $articulos, $descripciones);
            }
            http_response_code(200);
            echo json_encode(["mensaje" =>"Hecho"]);
            exit;
        }

        if (isset($_GET['datos_supervisor'])){
            $folio = $_GET['folio'];
            $codigo = $_GET['codigo'];

            $nombre_supervisor = $pdo->query("SELECT concat(nombre, ' ', apellidoPat, ' ', apellidoMat) as nombre
            from sistemanomina_prueba.datospersonalesempleado dpe 
            inner join usuarios u on u.codNoEmpleado=dpe.idEmpleado
            where idEmpleado = $codNoEmpleado")->fetch(PDO::FETCH_ASSOC);
            echo json_encode(["supervisor" => $nombre_supervisor]);
            exit;
        }
        if( isset($_GET['htas_vencidas'])){
            $herramientas = $pdo->query("SELECT p.idPedido,  p.idSupervisor,
            p.cargo as cargo, 
            (select c.NOMBRE from sae.clie01 c where c.CLAVE = p.cargo) as NOMBRE,
            ap.CVE_ART as clave, 
            (select i.DESCR  from sae.inve01 i where i.CVE_ART = ap.CVE_ART limit 1) as descripcion,
            date(p.fechaDevoluciones) as fechaDevoluciones, 
            (select concat(d.nombre, ' ', d.apellidoPat, ' ', d.apellidoMat)
                from sistemanomina_prueba.datospersonalesempleado d 
                where  d.idEmpleado= p.idSupervisor) as supervisor,
            DATEDIFF(NOW(),date(p.fechaDevoluciones)) as diasVencidos
            from pedidos p
            inner join articulos_pedidos ap on ap.idPedido = p.idPedido
            where date(p.fechaDevoluciones) <=  date(now())
            and p.tipoPedido in (2,3,6) and p.fechaDevoluciones != 0 and p.sucursal = $sucursal
            and ap.CVE_ART like '3020%'  and year(p.fechaDevoluciones) >= $periodo")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["herramientas" => $herramientas]);
            exit;
        }
        if( isset($_GET['bitacora_herramientas'])){
            $herramientas = $pdo->query("SELECT x.CVE_ART, i.DESCR
                from sae.mult01 x
                inner join sae.inve01 i on i.CVE_ART = x.CVE_ART
                where x.CVE_ART  like '3020%' and x.CVE_ART not like '%999' and  x.CVE_ART not like '%000'
            and x.CVE_ALM = $almacen")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["herramientas" => $herramientas]);
            exit;
        }
        if( isset($_GET['bitacora_herramienta'])){
            $articulo = $_GET["articulo"];
            $herramienta = $pdo->query("SELECT * from (SELECT 'No Aplica' as tiempo, 'No Aplica' as fechaSurtio, date(p.fechaSolicitud) as fechaSolicitud, ifnull(ap.folio, 'Sin folio') as folio, ap.CANT, p.cargo, c.NOMBRE, date(p.fechaRequerida) as fechaRequerida,
                date(p.fechaDevoluciones) as fechaDevoluciones, 'No aplica' as fechaDevolucionReal, ifnull(v.NOMBRE, 'Sin información') as VEND
                from pedidosalmacen.pedidos p 
                inner join pedidosalmacen.articulos_pedidos ap on ap.idPedido = p.idPedido 
                inner join sae.clie01 c on c.CLAVE = p.cargo
                inner join sae.vend01 v on v.CVE_VEND = c.CVE_VEND
                where tipoPedido in (2,3,6) and ap.CVE_ART = '$articulo' 
                and sucursal = '$sucursal'
                union
                SELECT CONCAT(DATEDIFF(d.fechaSolicitud , mhm.fecha_movimiento), ' Dias') as tiempo, date(d.fechaSolicitud) as fechaSurtio, 'No Aplica' as fechaSolicitud, ifnull(mhm.folio, 'Sin folio') as folio, mhm.cantidad as cant_devolucion, 
                (select clave from sae.clie01 where clave = d.cargo) as cargo, 
                (select nombre from sae.clie01 where clave = d.cargo) as NOMBRE, 
                'No aplica' as fechaRequerida, 'No aplica' as fechaDevoluciones,
                date(mhm.fecha_movimiento) as fechaDevolucionReal,
                (select concat(nombre, ' ', apellidoPat, ' ', apellidoMat) 
                from sistemanomina_prueba.datospersonalesempleado where idEmpleado = d.autorizo)  as autorizo
                from pedidosalmacen.articulos_devoluciones ad
                inner join pedidosalmacen.devoluciones d on d.idDevolucion = ad.idDevolucion
                inner join pedidosalmacen.pedidos p on p.idPedido = ad.idPedido
                inner join pedidosalmacen.articulos_pedidos ap on ap.idPedido = p.idPedido
                inner join (select * from pedidosalmacen.minve_htta_mayor where concepto = 11) mhm on mhm.folio =  ap.FOLIO
                where ad.CVE_ART = '$articulo'
                and p.sucursal= '$sucursal') p
                order by folio asc")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["herramienta" => $herramienta]);
            exit;
        }

        if(isset($_GET['ubicacion_herramienta_dos'])){

            // echo "SELECT i.CVE_ART, i.DESCR, i.UNI_MED, m.CTRL_ALM, m.EXIST, m.COMP_X_REC 
            // FROM INVE01 i
            // INNER JOIN (select CVE_ART, CTRL_ALM, EXIST, COMP_X_REC  from MULT01
            // where CVE_ALM = $almacen) m ON m.CVE_ART = i.CVE_ART
            // WHERE i.CVE_ART  like '3020%' and i.CVE_ART not like '%999' and  i.CVE_ART not like '%000' and m.EXIST > 0";
            
            $herramientas = $sae->query("SELECT i.CVE_ART, i.DESCR, i.UNI_MED, m.CTRL_ALM, m.EXIST, m.COMP_X_REC 
            FROM INVE01 i
            INNER JOIN (select CVE_ART, CTRL_ALM, EXIST, COMP_X_REC  from MULT01
            where CVE_ALM = $almacen) m ON m.CVE_ART = i.CVE_ART
            WHERE i.CVE_ART  like '3020%' and i.CVE_ART not like '%999' and  i.CVE_ART not like '%000' and m.EXIST > 0")->fetchAll(PDO::FETCH_ASSOC);

            foreach ($herramientas as $index => $herramienta) {
                $clave = $herramientas[$index]['CVE_ART'];
                $exist = intval($herramientas[$index]['EXIST']);
                // echo $exist;
                $herramientas[$index]["DESCR"] = utf8_encode($herramienta["DESCR"]);
                $herramientas[$index]["UNI_MED"] = utf8_encode($herramienta["UNI_MED"]);

                $herramientas[$index]['DESCR'] = utf8_encode($herramientas[$index]['DESCR']);

                $herramientas[$index]["devoluciones"] = $pdo->query("SELECT * from (
                    select *, sum(simbolo) as suma from (
                    SELECT mht.fecha_movimiento, mht.cantidad, 
                    (select concat(cargo, ' - ', nombre) from sae.clie01 where CLAVE = p.cargo) as cargo,
                    (select concat(nombre, ' ', apellidoPat, ' ', apellidoMat) 
                                        from sistemanomina_prueba.datospersonalesempleado where idEmpleado = p.idSolicitante)  as solicito,
                    (select concat(nombre, ' ', apellidoPat, ' ', apellidoMat) 
                                        from sistemanomina_prueba.datospersonalesempleado where idEmpleado = p.idSupervisor)  as autorizo,
                    date(p.fechaDevoluciones) as fechaDevoluciones,
                    case estatus when 1 then 1 else -1 end as simbolo,
                    mht.folio
                    FROM pedidosalmacen.minve_htta_mayor mht
                    inner join articulos_pedidos ap on ap.CVE_ART = claveArt
                    inner join pedidos p on p.idPedido = ap.idPedido
                    WHERE claveArt= '$clave' and ap.FOLIO = mht.folio) p
                    group by folio) p2
                    where suma!=0
                    order by fecha_movimiento desc
                    limit $exist")->fetchAll(PDO::FETCH_ASSOC);

                $suma = 0;
                foreach($herramientas[$index]["devoluciones"] as $i => $fila){
                    $suma+= $fila["cantidad"];
                }
                $herramientas[$index]["disponibles"] = $herramientas[$index]['EXIST'] - $suma;

                // $herramientas[$index]["disponibles"] = $herramientas[$index]['EXIST'] - count($herramientas[$index]["devoluciones"]);
                
                // $herramientas[$index]["disponibles"] = $herramientas[$index]['EXIST'] - ($pdo->query("SELECT sum(cantidad) as cantidad 
                //     from (
                //     select *, sum(simbolo) as suma from (
                //     SELECT mht.cantidad,
                //     case estatus when 1 then 1 else -1 end as simbolo,
                //     mht.folio
                //     FROM pedidosalmacen.minve_htta_mayor mht
                //     inner join articulos_pedidos ap on ap.CVE_ART = claveArt
                //     inner join pedidos p on p.idPedido = ap.idPedido
                //     WHERE claveArt= '$clave' and ap.FOLIO = mht.folio) p
                //     group by folio) p2
                //     where suma!=0")->fetch(PDO::FETCH_COLUMN));
            }
            http_response_code(200);
            echo json_encode(["herramientas" => $herramientas]);
            // echo json_last_error_msg();
            exit;
        }

        if (isset($_GET['ubicacion_herramienta'])) {
            

            $herramientas = $pdo->query("SELECT 
                x.CVE_ART, 
                x.DESCR, 
                ifnull(cant_uno, 0) as cant_uno, 
                ifnull(cant_dos, 0) as cant_dos,
                x.UNI_MED, 
                (ifnull(solicitantes, 'Ninguno')) as solicitantes, 
                (ifnull(nombre_solicitante, 'Ninguno')) as nombre_solicitante, 
                (ifnull(cargos, 'Ninguno')) as cargos, 
                (ifnull(fechaDevoluciones_dos, 'Ninguno')) as fechaDevoluciones_dos, 
                (ifnull(fechaDevoluciones_tres, 'Ninguno')) as fechaDevoluciones_tres, 
                (ifnull(exist_4, 0)) as EXIST,
                (- ifnull(cant_uno, 0) - ifnull(cant_dos, 0) + ifnull(exist_4, 0)) as disponibles, 
                (ifnull(comp_rec, 0)) as COMP_X_REC, (ctrl_al) as CTRL_ALM,
                (ifnull(horaEntrega, 'Ninguno')) as horaEntrega, 
                (ifnull(recibe,'Ninguno'))  as recibe, 
                ifnull(nombre_vendedor, 'Ninguno') as nombre_vendedor, 
                ifnull(cant_mayor, 'Ninguno') as cant_mayor, 
                ifnull(cant_personal, 'Ninguno') as cant_personal,
                (SELECT  date(fecha)  FROM pedidosalmacen.mantenimiento_hta_mayor
					where clave = x.CVE_ART  and sucursal = $sucursal
					order by fecha desc
				limit 1) as fecha_ult_mtto,
                (SELECT  count(id)  FROM pedidosalmacen.mantenimiento_hta_mayor
					where clave = x.CVE_ART  and sucursal = $sucursal
					order by fecha desc
				limit 1) as cant_mtto,
                id_art_pedido, idPedido
                FROM sae.INVE01 x
                left join (select group_concat(date(horaEntrega)) as horaEntrega, CVE_ART,
                group_concat(distinct recibe) as recibe from (
                            select ec.horaEntrega, CVE_ART, ec.recibe
                            from entregas_compras ec
                            inner join articulos_pedidos ap2 on ap2.idPedido = ec.idPedido 
                            inner join pedidos p2 on p2.idPedido = ap2.idPedido 
                            where p2.sucursal = $sucursal
                            ) p group by CVE_ART) hEntrega on hEntrega.CVE_ART = x.CVE_ART 
                left join (select EXIST as exist_4, CVE_ART, COMP_X_REC as comp_rec, CTRL_ALM as ctrl_al from (
                        select m.EXIST, m.CVE_ART, m.COMP_X_REC, m.CTRL_ALM  
                        from sae.mult01 m where m.CVE_ALM = $almacen) p group by CVE_ART) alma_4 on alma_4.CVE_ART = x.CVE_ART 
                left join (
                        select count(CVE_ART) as dos, sum(CANT) as cant_uno, CVE_ART, group_concat(  cargo SEPARATOR '+') as cargos,
                        group_concat( date(fechaDevoluciones)) as fechaDevoluciones_dos, 
                        group_concat( nombre_vendedor) AS nombre_vendedor,
                        group_concat( nombre_solicitante) AS nombre_solicitante,
                        group_concat(CANT) as cant_mayor,
                        group_concat(id) as id_art_pedido,
                        group_concat(idPedido) as idPedido from (
                                select ap.idPedido, ap.status, ap.CVE_ART, ap.CANT, p.fechaDevoluciones, 
                                concat(p.cargo,' - ',c.NOMBRE) as cargo, v.NOMBRE as nombre_vendedor,
                                concat(dpe2.nombre, ' ', dpe2.apellidoPat, ' ', dpe2.apellidoMat) as nombre_solicitante,
                                id
                                from articulos_pedidos ap
                                inner join pedidos p on p.idPedido = ap.idPedido 
                                inner join sistemanomina_prueba.datospersonalesempleado dpe2 on dpe2.idEmpleado = p.idSolicitante
                                inner join sae.clie01 c on c.CLAVE = p.cargo
                                inner join sae.vend01 v on c.CVE_VEND = v.CVE_VEND
                                where ap.STATUS =7 and p.tipoPedido in (2,6) and p.fechaDevoluciones!=0
                                and p.sucursal = $sucursal
                                ) p group by CVE_ART
                        ) entregados on entregados.CVE_ART = x.CVE_ART
                left join (
                        select count(CVE_ART) as tres, sum(CANT) as cant_dos, CVE_ART, group_concat(  nombre) as solicitantes,
                        group_concat( date(fechaDevoluciones)) as fechaDevoluciones_tres, group_concat(CANT) as cant_personal 
                        from (
                                select ap.idPedido, ap.status, ap.CVE_ART, ap.CANT, concat(nombre, ' ', apellidoPat, ' ', apellidoMat) as nombre, p.fechaDevoluciones
                                from articulos_pedidos ap
                                inner join pedidos p on p.idPedido = ap.idPedido  
                                inner join sistemanomina_prueba.datospersonalesempleado dpe on dpe.idEmpleado = p.idSolicitante
                                inner join usuarios u on u.codNoEmpleado=dpe.idEmpleado
                                where ap.STATUS =7 and p.tipoPedido in (3,6) and p.fechaDevoluciones!=0
                                and p.sucursal = $sucursal
                                ) p group by CVE_ART
                        ) hta_personal on hta_personal.CVE_ART = x.CVE_ART
                where x.CVE_ART  like '3020%' and x.CVE_ART not like '%999' and  x.CVE_ART not like '%000' and exist_4 >0")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($herramientas as $index => $herramienta) {
                $clave = $herramientas[$index]['CVE_ART'];
                $temp = $herramientas[$index]["EXIST"];
                $herramientas[$index]["EXIST"] = $sae->query("SELECT EXIST FROM MULT01 WHERE CVE_ART = '$clave' and CVE_ALM = $almacen")->fetch(PDO::FETCH_COLUMN);
                if(!$herramientas[$index]["EXIST"]){
                    $herramientas[$index]["EXIST"] = 0;
                }
                $herramientas[$index]["disponibles"] = $herramientas[$index]["disponibles"] - $temp + $herramientas[$index]["EXIST"];
            }
            echo json_encode(["herramientas" => $herramientas]);
            exit;
        } 
        if (isset($_GET['codNoEmpleado'])) {
            $id = $_GET['codNoEmpleado'];
            $query = "";
            if (isset($_GET['finalizados'])) {
                $fecha = "fechaCierre as fechaSolicitud";
                $query = " AND p.status in (3, 9, 10) ";
            } else {
                $fecha = "fechaSolicitud";
                $query = " AND p.status not in (3, 9, 10) ";
            }

            if (isset($_GET['status'])) {
                $status = $_GET['status'];
                switch ($status) {
                    case "Finalizados":
                        $fecha = "fechaCierre as fechaSolicitud";
                        $query = "and p.status = 9";
                        break;
                    case "Procesando":
                        $fecha = "fechaSolicitud";
                        $query = "and p.status != 9 and p.status != 3 and p.status != 10 and p.status != 1";
                        break;
                    case "ProcesandoDespacho":
                        $fecha = "fechaSolicitud";
                        $query = "and p.status in (1,2,4)";
                        break;
                    case "ProcesandoCompras":
                        $fecha = "fechaSolicitud";
                        $query = "and p.status = 5";
                        break;
                    case "ProcesandoComprasRecibidas":
                        $fecha = "fechaSolicitud";
                        $query = "and p.status = 9";
                        break;
                }
            }
            $pedidos = $pdo->query("SELECT
            idPedido,
            cargo,
            c.nombre,
            descripcion,
            indicacionAdicional,
            idSolicitante,
            concat(dpr.apellidoPat, ' ', dpr.apellidoMat, ' ', dpr.nombre) as nombreSolicitante,
            idSupervisor,
            concat(dps.apellidoPat, ' ', dps.apellidoMat, ' ', dps.nombre) as nombreSupervisor,
            DATE(fechaRequerida) AS fechaRequerida,
            TIME(fechaRequerida) AS horaRequerida,
            date(fechaDevoluciones) as fechaDevoluciones,
            $fecha,
            p.status,
            tipoPedido,
            ruta
            FROM
            pedidos p
            left join sae.clie01 c on c.CLAVE = cargo
            inner join sistemanomina_prueba.datospersonalesempleado dpr on dpr.idEmpleado = idSolicitante
            inner join sistemanomina_prueba.datospersonalesempleado dps on dps.idEmpleado = idSupervisor
            where (idSolicitante = $id or idSupervisor = $id) $query and tipoPedido not in (5) and traspaso is null order by idPedido desc")->fetchAll(PDO::FETCH_ASSOC);
            // foreach ($pedidos as $index => $pedido) {COMENTADO POR FERNANDO HDZ 07/05/2022
                // $idPedido = $pedido["idPedido"];
                // $pendientes = 0;//$pdo->query("SELECT count(idPedido) as pendientes  FROM pedidosalmacen.articulos_pedidos where idPedido = '$idPedido' and status in(1)")->fetch(PDO::FETCH_COLUMN);
                // $encompra = 0;//$pdo->query("SELECT count(idPedido) as encompra FROM pedidosalmacen.articulos_pedidos where idPedido = '$idPedido' and status in(2,3,4)")->fetch(PDO::FETCH_COLUMN);
                // $pedidos[$index]["artPendientes"] = $pendientes;
                // $pedidos[$index]["artEnCompra"] = $encompra;
            // } COMENTADO POR FERNANDO HDZ 07/05/2022
            if (isset($_GET['status'])) {
                echo json_encode(["pedidos" => $pedidos]);
                exit;
            } else {
                echo json_encode($pedidos);
                exit;
            }
        }
        if (isset($_GET['sucursal_traspaso'])) {
            $id = $_GET['sucursal_traspaso'];

            $traspasos = $pdo->query("SELECT date(p.fechaRequerida) as fechaRequerida, cargo, p.idPedido, 'Almacen' as tipo,
                case ta.sucursal_origen
                    when 1 Then 'Desde Nuevo Laredo'
                    when 2 Then 'Desde Reynosa'
                    when 3 Then 'Desde Monterrey'
                    Else 'Ninguno'
                End as titulo, 
            year(p.fechaRequerida) as anio, ELT(MONTH(p.fechaRequerida), 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic') as mes, day(p.fechaRequerida) as dia, ta.fechaSolicitudTraspaso,
                case ta.sucursal_origen
                    when 1 Then 'Nuevo Laredo'
                    when 2 Then 'Reynosa'
                    when 3 Then 'Monterrey'
                    Else 'Ninguno'
                End as sucursal_origen, 
                case ta.sucursal_destino
                    when 1 Then 'Nuevo Laredo'
                    when 2 Then 'Reynosa'
                    when 3 Then 'Monterrey'
                    Else 'Ninguno'
                End as sucursal_destino,
            ta.sucursal_destino as destino, ta.sucursal_origen as origen,'Se estan mandando este pedido de articulos para que sea surtida' as descripcion
            from traspasos_almacen ta 
            inner join pedidos p on p.idPedido = ta.idPedido
            where ta.sucursal_destino = '$id' AND ta.autorizado is null 
            group by ta.idPedido;")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($traspasos);
                exit;
            
        }
        if (isset($_GET['codNoSupervisor'])) {
            $id = $_GET['codNoSupervisor'];
            if (isset($_GET['notificacion'])) {
                $cantidad = $pdo->query("SELECT count(*) from pedidos where idSupervisor = $id and status = 1 and tipoPedido != 4")->fetch(PDO::FETCH_COLUMN);
                $cantidadTraspasos = $pdo->query("SELECT count(*)
                FROM  pedidos p
                                        LEFT JOIN traspasos t ON t.idTraspaso = p.idTraspaso
                                        left join articulos_pedidos ap on t.idCveArt = ap.id
                                        LEFT JOIN pedidos pi ON ap.idPedido = pi.idPedido
                                        left JOIN  sae.clie01 c ON c.clave = p.cargo
                                        WHERE  p.status = 1 AND p.tipoPedido = 4 AND p.idTraspaso IS NOT NULL  AND pi.idSupervisor = $id;")->fetch(PDO::FETCH_COLUMN);
                echo json_encode(["error" => false, "cantidad" => ($cantidad + $cantidadTraspasos)]);
                exit;
            } else {
                $autorizacion = $pdo->query("SELECT 
                idPedido,
                cargo,
                nombre,
                descripcion,
                indicacionAdicional,
                idSolicitante,
                idSupervisor,
                DATE(fechaRequerida) AS fechaRequerida,
                TIME(fechaRequerida) AS horaRequerida,
                fechaDevoluciones,
                fechaSolicitud,
                p.status,
                tipoPedido
            FROM
                pedidos p
                left join sae.clie01 c on c.CLAVE = cargo
            WHERE
                p.status = 1 AND idSupervisor = $id and tipoPedido != 4;")->fetchAll(PDO::FETCH_ASSOC);

                /*$traspasos = $pdo->query("SELECT 
                ap.idPedido,
                p.cargo,
                c.nombre,
                p.descripcion,
                p.indicacionAdicional,
                p.idSolicitante,
                p.idSupervisor,
                DATE(p.fechaRequerida) AS fechaRequerida,
                TIME(p.fechaRequerida) AS horaRequerida,
                p.fechaDevoluciones,
                p.fechaSolicitud,
                p.status,
                p.tipoPedido
            FROM
                pedidos p
                    INNER JOIN traspasos t ON t.idTraspaso = p.idTraspaso
                    INNER JOIN articulos_pedidos ap on ap.id = t.idCveArt
                    INNER JOIN  pedidos pi ON ap.idPedido = pi.idPedido
                    left JOIN sae.clie01 c ON c.clave = p.cargo
            WHERE p.status = 1 AND p.tipoPedido = 4 AND p.idTraspaso IS NOT NULL AND pi.idSupervisor = $id;")->fetchAll(PDO::FETCH_ASSOC);*/


            $traspasos = $pdo->query("SELECT 
                p.idPedido,
                p.cargo,
                c.nombre,
                p.descripcion,
                p.indicacionAdicional,
                p.idSolicitante,
                p.idSupervisor,
                DATE(p.fechaRequerida) AS fechaRequerida,
                TIME(p.fechaRequerida) AS horaRequerida,
                p.fechaDevoluciones,
                p.fechaSolicitud,
                p.status,
                p.tipoPedido
            FROM
                pedidos p
                    INNER JOIN traspasos t ON t.idTraspaso = p.idTraspaso
                    -- INNER JOIN articulos_pedidos ap on ap.id = t.idCveArt
                    -- INNER JOIN  pedidos pi ON ap.idPedido = pi.idPedido
                    left JOIN sae.clie01 c ON c.clave = p.cargo
            WHERE p.status = 1 AND p.tipoPedido = 4 AND p.idTraspaso IS NOT NULL AND p.idSupervisor = $id;")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(array_merge($autorizacion, $traspasos));
                exit;
            }
        }

        if (isset($_GET['codNoSupervisor'])) {
            $id = $_GET['codNoSupervisor'];
            if (isset($_GET['notificacion'])) {
                $cantidad = $pdo->query("SELECT count(*) from pedidos where idSupervisor = $id and status = 1 and tipoPedido != 4")->fetch(PDO::FETCH_COLUMN);
                $cantidadTraspasos = $pdo->query("SELECT count(*)
                FROM  pedidos p
                                        LEFT JOIN traspasos t ON t.idTraspaso = p.idTraspaso
                                        left join articulos_pedidos ap on t.idCveArt = ap.id
                                        LEFT JOIN pedidos pi ON ap.idPedido = pi.idPedido
                                        left JOIN  sae.clie01 c ON c.clave = p.cargo
                                        WHERE  p.status = 1 AND p.tipoPedido = 4 AND p.idTraspaso IS NOT NULL  AND pi.idSupervisor = $id;")->fetch(PDO::FETCH_COLUMN);
                echo json_encode(["error" => false, "cantidad" => ($cantidad + $cantidadTraspasos)]);
                exit;
            } else {
                $autorizacion = $pdo->query("SELECT 
                idPedido,
                cargo,
                nombre,
                descripcion,
                indicacionAdicional,
                idSolicitante,
                idSupervisor,
                DATE(fechaRequerida) AS fechaRequerida,
                TIME(fechaRequerida) AS horaRequerida,
                fechaDevoluciones,
                fechaSolicitud,
                p.status,
                tipoPedido
            FROM
                pedidos p
                left join sae.clie01 c on c.CLAVE = cargo
            WHERE
                p.status = 1 AND idSupervisor = $id and tipoPedido != 4;")->fetchAll(PDO::FETCH_ASSOC);

                /*$traspasos = $pdo->query("SELECT 
                ap.idPedido,
                p.cargo,
                c.nombre,
                p.descripcion,
                p.indicacionAdicional,
                p.idSolicitante,
                p.idSupervisor,
                DATE(p.fechaRequerida) AS fechaRequerida,
                TIME(p.fechaRequerida) AS horaRequerida,
                p.fechaDevoluciones,
                p.fechaSolicitud,
                p.status,
                p.tipoPedido
            FROM
                pedidos p
                    INNER JOIN traspasos t ON t.idTraspaso = p.idTraspaso
                    INNER JOIN articulos_pedidos ap on ap.id = t.idCveArt
                    INNER JOIN  pedidos pi ON ap.idPedido = pi.idPedido
                    left JOIN sae.clie01 c ON c.clave = p.cargo
            WHERE p.status = 1 AND p.tipoPedido = 4 AND p.idTraspaso IS NOT NULL AND pi.idSupervisor = $id;")->fetchAll(PDO::FETCH_ASSOC);*/


            $traspasos = $pdo->query("SELECT 
                p.idPedido,
                p.cargo,
                c.nombre,
                p.descripcion,
                p.indicacionAdicional,
                p.idSolicitante,
                p.idSupervisor,
                DATE(p.fechaRequerida) AS fechaRequerida,
                TIME(p.fechaRequerida) AS horaRequerida,
                p.fechaDevoluciones,
                p.fechaSolicitud,
                p.status,
                p.tipoPedido
            FROM
                pedidos p
                    INNER JOIN traspasos t ON t.idTraspaso = p.idTraspaso
                    -- INNER JOIN articulos_pedidos ap on ap.id = t.idCveArt
                    -- INNER JOIN  pedidos pi ON ap.idPedido = pi.idPedido
                    left JOIN sae.clie01 c ON c.clave = p.cargo
            WHERE p.status = 1 AND p.tipoPedido = 4 AND p.idTraspaso IS NOT NULL AND p.idSupervisor = $id;")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(array_merge($autorizacion, $traspasos));
                exit;
            }
        }

        if (isset($_GET['idPedidoTraspaso'])) {
            $tiempo_inicial = microtime(true);
            $id = $_GET['idPedidoTraspaso'];
            $pedido = $pdo->query("SELECT p.idPedido, cargo,p.sucursal,indicacionAdicional,
            (select nombre from sae.clie01 c where c.clave = p.cargo) AS nombreCargo,
            idSupervisor,
            (select CONCAT(nombre, ' ',apellidoPat,' ', apellidoMat) FROM sistemanomina_prueba.datospersonalesempleado WHERE idEmpleado = p.idSolicitante) AS nombreSolicitante,
            idSolicitante,
            (select CONCAT(nombre, ' ',apellidoPat,' ', apellidoMat) FROM sistemanomina_prueba.datospersonalesempleado WHERE idEmpleado = p.idSupervisor) AS nombreSupervisor ,
            case ruta  when 1 then date_format(fechaRequerida, '%d/%m/%Y') when 2 then date_format(fechaRequerida, '%d/%m/%Y')
            when 3 then date_format(fechaRequerida, '%d/%m/%Y %H:%i:%s')  when 4 then date_format(fechaRequerida, '%d/%m/%Y %H:%i:%s') end as fechaRequerida, 
            fechaSolicitud, date_format(fechaDevoluciones, '%d/%m/%Y') as fechaDevoluciones, descripcion, p.status, tipoPedido,  
            (select motivo from rechazos_pedidos where idPedido = p.idPedido ) as motivo, ruta, codPersona 
            FROM  pedidos p
            WHERE p.idPedido = $id;")->fetch(PDO::FETCH_ASSOC);
            //if ($pedido['tipoPedido'] == 2 || $pedido['tipoPedido'] == 3 || $pedido['tipoPedido'] == 6) {
            //    if ($pedido['codPersona'] != null) {
            //        $persona = $pedido['codPersona'];
            //        $pedido['recibe'] = utf8_encode($sae->query("SELECT NOMBRE FROM CLIE01 WHERE CLAVE = '$persona'")->fetch(PDO::FETCH_COLUMN));
            //    }
            //}
            $tiempo_final = microtime(true);
            // echo "PRIMERA CONSULTA " . ($tiempo_final - $tiempo_inicial);
            // echo "<br>";
            $sucursal = $pedido['sucursal'];
            switch($sucursal){
                case '1':
                    $almacen = 1;
                    break;
                case '2':
                    $almacen = 4;
                    break;
                case '3':
                    $almacen = 7;
                    break;
            }
            $arrayCompras = array();
            $pedido['articulos'] = $pdo->query("SELECT if(i.DESCR is null, null, ap.CVE_ART)  as CVE_ART,
            (select CTRL_ALM  from sae.mult01 where  cve_art = ap.CVE_ART and CVE_ALM = '$almacen'  group by CVE_ALM) as CTRL_ALM, 
            ifnull(i.DESCR, ap.CVE_ART) 
            as DESCR, if(ap.STATUS = 7 and ap.CVE_COMPRA is null, ENTREGADO,CANT)  AS CANT,ENTREGADO, i.UNI_MED, ap.STATUS, 
            (select descripcion  from cat_status_articulo where idStatus = ap.status)  AS DESCRIPCION, 
            ifnull(CVE_COMPRA, '') as CVE_COMPRA, id, '0' AS ESPECIAL
            from articulos_pedidos ap
            inner join sae.inve01 i on i.cve_art = ap.CVE_ART 
            where idPedido = $id and ap.STATUS = '17'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pedido['articulos'] as $index => $art) {
                $arrayCompras[] = "p," . $art['id'];
                $codigo = $art['CVE_ART'];
                $existencias = $sae->query("SELECT M.CVE_ART, iif(SUM(M.STOCK_MIN) > 0, 'STOCK', 'SIN STOCK') as TIPO,
                SUM(CASE WHEN M.CVE_ALM in (1,3) THEN M.EXIST ELSE 0 END) AS STOCKNLD,
                SUM(CASE WHEN M.CVE_ALM in( 4 )THEN M.EXIST ELSE 0 END) AS STOCKREY,
                SUM(CASE WHEN M.CVE_ALM in ( 7,9) THEN M.EXIST ELSE 0 END) AS STOCKMTY
                FROM mult01 m
                WHERE CVE_ART = '$codigo'
                GROUP BY CVE_ART")->fetch(PDO::FETCH_ASSOC);
                if ($existencias) {
                    $pedido['articulos'][$index]["STOCK_NLD"] = $existencias['STOCKNLD'];
                    $pedido['articulos'][$index]["STOCK_REY"] = $existencias['STOCKREY'];
                    $pedido['articulos'][$index]["STOCK_MTY"] = $existencias['STOCKMTY'];
                    $pedido['articulos'][$index]["TIPO"] = $existencias['TIPO'];
                    $pedido['articulos'][$index]["SUCURSAL"] = $sucursal;//Agregado para que en la pistola muestre el stock correctamente
                } else {
                    $pedido['articulos'][$index]["STOCK_NLD"] = 0;
                    $pedido['articulos'][$index]["STOCK_REY"] = 0;
                    $pedido['articulos'][$index]["STOCK_MTY"] = 0;
                    $pedido['articulos'][$index]["CTRL_ALM"] = "";
                    $pedido['articulos'][$index]["TIPO"] = "SIN STOCK";
                    $pedido['articulos'][$index]["SUCURSAL"] = $sucursal;//Agregado para que en la pistola muestre el stock correctamente

                }
            }
            $tiempofinalarticulos = microtime(true);
            // echo "FOREACH ART CONSULTA " . ($tiempofinalarticulos - $tiempo_final);
            // echo "<br>";
            $pedido['especiales'] = $pdo->query("SELECT aep.* ,csa.descripcion AS DESCRIPCION, idEspecial as id, '1' as ESPECIAL
            FROM articulos_especiales_pedidos aep 
            INNER JOIN cat_status_articulo csa on csa.idStatus = aep.status
            WHERE aep.idPedido = $id  and aep.STATUS = '17'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pedido['especiales'] as $index => $especial) {
                $arrayCompras[] = "e," . $especial['id'];
                if ($especial['NUM_PAR'] && $especial['CVE_COMPRA']) {
                    $folioCompra = $especial['CVE_COMPRA'];
                    $numPar = $especial['NUM_PAR'];
                    $pedido['especiales'][$index]['CVE_ART'] = $sae->query("SELECT CVE_ART FROM PAR_COMPO01 WHERE CVE_DOC = '$folioCompra' and NUM_PAR = $numPar")->fetch(PDO::FETCH_COLUMN);
                } else {
                    $pedido['especiales'][$index]['CVE_ART'] = "";
                }
                $pedido['especiales'][$index]['ESPECIAL'] = 1;
            }
            $tiempofinalespeciales = microtime(true);
            // echo "FOREACH ART CONSULTA " . ($tiempofinalespeciales - $tiempofinalarticulos);
            // echo "<br>";
            $pedido['folios'] = $pdo->query("SELECT 
            -- idPedido,
            folio,
            -- surtidor,
            fechaEntrega as   firmaSurtidor,
            -- recibe AS receptor,
            fechaEntrega as  firmaReceptor,
            fechaEntrega,
            (SELECT 
                    COUNT(cve_art)
                FROM
                    articulos_pedidos
                WHERE
                    FOLIO = f.folio) AS cantidad
                FROM folios f
                WHERE idPedido = $id;")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($arrayCompras)) {
                $stringCompras = implode("','", $arrayCompras);
                // Se trae lo de COMPC porque tiene que se rlo recibido.
                $pedido['compras'] = $sae->query("SELECT DISTINCT CLAVE_DOC as CVE_COMPRA FROM PAR_COMPC_CLIB01 pcc WHERE CAMPLIB1 IN ('$stringCompras');")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $pedido['compras'] = [];
            }
            
            foreach ($pedido['compras'] as $index => $c) {
                $idCompra = $c['CVE_COMPRA'];
                $firma = $pdo->query("SELECT idPedido from entregas_compras where idPedido = $id and idCompra = '$idCompra';")->fetch(PDO::FETCH_COLUMN);
                if ($firma) {
                    $pedido['compras'][$index]['FIRMADO'] = true;
                } else {
                    $pedido['compras'][$index]['FIRMADO'] = false;
                }
            }
            $tiempofolios = microtime(true);
            // echo "folios CONSULTA " . ($tiempofolios - $tiempofinalespeciales);
            // echo "<br>";
            echo json_encode($pedido);
            exit;
            
        }

        if (isset($_GET['idPedido'])) {
            $tiempo_inicial = microtime(true);
            $id = $_GET['idPedido'];
            $pedido = $pdo->query("SELECT p.idPedido, cargo,p.sucursal,indicacionAdicional,
            (select nombre from sae.clie01 c where c.clave = p.cargo) AS nombreCargo,
            idSupervisor,
            (select CONCAT(nombre, ' ',apellidoPat,' ', apellidoMat) FROM sistemanomina_prueba.datospersonalesempleado WHERE idEmpleado = p.idSolicitante) AS nombreSolicitante,
            idSolicitante,
            (select CONCAT(nombre, ' ',apellidoPat,' ', apellidoMat) FROM sistemanomina_prueba.datospersonalesempleado WHERE idEmpleado = p.idSupervisor) AS nombreSupervisor ,
            case ruta  when 1 then date_format(fechaRequerida, '%d/%m/%Y') when 2 then date_format(fechaRequerida, '%d/%m/%Y')
            when 3 then date_format(fechaRequerida, '%d/%m/%Y %H:%i:%s')  when 4 then date_format(fechaRequerida, '%d/%m/%Y %H:%i:%s') end as fechaRequerida, 
            fechaSolicitud, date_format(fechaDevoluciones, '%d/%m/%Y') as fechaDevoluciones, descripcion, p.status, tipoPedido,  
            (select motivo from rechazos_pedidos where idPedido = p.idPedido ) as motivo, ruta, codPersona 
            FROM  pedidos p
            WHERE  p.idPedido = $id;")->fetch(PDO::FETCH_ASSOC);
            if ($pedido['tipoPedido'] == 2 || $pedido['tipoPedido'] == 3 || $pedido['tipoPedido'] == 6) {
                if ($pedido['codPersona'] != null) {
                    $persona = $pedido['codPersona'];
                    $pedido['recibe'] = utf8_encode($sae->query("SELECT NOMBRE FROM CLIE01 WHERE CLAVE = '$persona'")->fetch(PDO::FETCH_COLUMN));
                }
            }
            $tiempo_final = microtime(true);
            $sucursal = $pedido['sucursal'];
            switch($sucursal){
                case '1':
                    $almacen = 1;
                    break;
                case '2':
                    $almacen = 4;
                    break;
                case '3':
                    $almacen = 7;
                    break;
            }
            $arrayCompras = array();
            $pedido['articulos'] = $pdo->query("SELECT if(i.DESCR is null, null, ap.CVE_ART)  as CVE_ART,
            (select CTRL_ALM  from sae.mult01 where  cve_art = ap.CVE_ART and CVE_ALM = '$almacen'  group by CVE_ALM) as CTRL_ALM, 
            ifnull(i.DESCR, ap.CVE_ART) 
            as DESCR, if(ap.STATUS = 7 and ap.CVE_COMPRA is null, ENTREGADO,CANT)  AS CANT,ENTREGADO, i.UNI_MED, ap.STATUS, 
            (select descripcion  from cat_status_articulo where idStatus = ap.status)  AS DESCRIPCION, 
            ifnull(CVE_COMPRA, '') as CVE_COMPRA, id, '0' AS ESPECIAL
            from articulos_pedidos ap
            inner join sae.inve01 i on i.cve_art = ap.CVE_ART 
            where idPedido   = $id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pedido['articulos'] as $index => $art) {
                $arrayCompras[] = "p," . $art['id'];
                $codigo = $art['CVE_ART'];
                $existencias = $sae->query("SELECT M.CVE_ART, iif(SUM(M.STOCK_MIN) > 0, 'STOCK', 'SIN STOCK') as TIPO,
                SUM(CASE WHEN M.CVE_ALM in (1,3) THEN M.EXIST ELSE 0 END) AS STOCKNLD,
                SUM(CASE WHEN M.CVE_ALM in( 4 )THEN M.EXIST ELSE 0 END) AS STOCKREY,
                SUM(CASE WHEN M.CVE_ALM in ( 7,9) THEN M.EXIST ELSE 0 END) AS STOCKMTY
                FROM mult01 m
                WHERE CVE_ART = '$codigo'
                GROUP BY CVE_ART")->fetch(PDO::FETCH_ASSOC);
                if ($existencias) {
                    $pedido['articulos'][$index]["STOCK_NLD"] = $existencias['STOCKNLD'];
                    $pedido['articulos'][$index]["STOCK_REY"] = $existencias['STOCKREY'];
                    $pedido['articulos'][$index]["STOCK_MTY"] = $existencias['STOCKMTY'];
                    $pedido['articulos'][$index]["TIPO"] = $existencias['TIPO'];
                    $pedido['articulos'][$index]["SUCURSAL"] = $sucursal;//Agregado para que en la pistola muestre el stock correctamente
                } else {
                    $pedido['articulos'][$index]["STOCK_NLD"] = 0;
                    $pedido['articulos'][$index]["STOCK_REY"] = 0;
                    $pedido['articulos'][$index]["STOCK_MTY"] = 0;
                    $pedido['articulos'][$index]["CTRL_ALM"] = "";
                    $pedido['articulos'][$index]["TIPO"] = "SIN STOCK";
                    $pedido['articulos'][$index]["SUCURSAL"] = $sucursal;//Agregado para que en la pistola muestre el stock correctamente

                }
            }
            $tiempofinalarticulos = microtime(true);
            $pedido['especiales'] = $pdo->query("SELECT aep.* ,csa.descripcion AS DESCRIPCION, idEspecial as id, '1' as ESPECIAL
            FROM articulos_especiales_pedidos aep 
            INNER JOIN cat_status_articulo csa on csa.idStatus = aep.status
            WHERE aep.idPedido = $id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pedido['especiales'] as $index => $especial) {
                $arrayCompras[] = "e," . $especial['id'];
                if ($especial['NUM_PAR'] && $especial['CVE_COMPRA']) {
                    $folioCompra = $especial['CVE_COMPRA'];
                    $numPar = $especial['NUM_PAR'];
                    $pedido['especiales'][$index]['CVE_ART'] = $sae->query("SELECT CVE_ART FROM PAR_COMPO01 WHERE CVE_DOC = '$folioCompra' and NUM_PAR = $numPar")->fetch(PDO::FETCH_COLUMN);
                } else {
                    $pedido['especiales'][$index]['CVE_ART'] = "";
                }
                $pedido['especiales'][$index]['ESPECIAL'] = 1;
            }
            $tiempofinalespeciales = microtime(true);
            $pedido['folios'] = $pdo->query("SELECT 
            folio,
            fechaEntrega as   firmaSurtidor,
            fechaEntrega as  firmaReceptor,
            fechaEntrega,
            (SELECT 
                    COUNT(cve_art)
                FROM
                    articulos_pedidos
                WHERE
                    FOLIO = f.folio) AS cantidad
                FROM folios f
                WHERE idPedido = $id;")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($arrayCompras)) {
                $stringCompras = implode("','", $arrayCompras);
                // Se trae lo de COMPC porque tiene que se rlo recibido.
                $pedido['compras'] = $sae->query("SELECT DISTINCT CLAVE_DOC as CVE_COMPRA FROM PAR_COMPC_CLIB01 pcc WHERE CAMPLIB1 IN ('$stringCompras');")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $pedido['compras'] = [];
            }
            
            foreach ($pedido['compras'] as $index => $c) {
                $idCompra = $c['CVE_COMPRA'];
                $firma = $pdo->query("SELECT idPedido from entregas_compras where idPedido = $id and idCompra = '$idCompra';")->fetch(PDO::FETCH_COLUMN);
                if ($firma) {
                    $pedido['compras'][$index]['FIRMADO'] = true;
                } else {
                    $pedido['compras'][$index]['FIRMADO'] = false;
                }
            }
            $tiempofolios = microtime(true);
            echo json_encode($pedido);
            exit;
            
        }
        
        if (isset($_GET['idPedidoPrueba'])) {
            $tiempo_inicial = microtime(true);
            ini_set('max_execution_time', '1000');
            $id = $_GET['idPedidoPrueba'];

            $pedido = $pdo->query("SELECT 
            p.idPedido,
            sucursal,
            cargo,
            indicacionAdicional,
            -- c.nombre AS nombreCargo,
            idSupervisor,
            CONCAT(dps.nombre,
                    ' ',
                    dps.apellidoPat,
                    ' ',
                    dps.apellidoMat) AS nombreSupervisor,
            idSolicitante,
            concat(dp.nombre, ' ', dp.apellidoPat, ' ', dp.apellidoMat) as nombreSolicitante, 
            case ruta 
            when 1 then date_format(fechaRequerida, '%d/%m/%Y')
            when 2 then date_format(fechaRequerida, '%d/%m/%Y')
            when 3 then date_format(fechaRequerida, '%d/%m/%Y %H:%i:%s') 
            when 4 then date_format(fechaRequerida, '%d/%m/%Y %H:%i:%s') 
            end as fechaRequerida, 
            fechaSolicitud, date_format(fechaDevoluciones, '%d/%m/%Y') as fechaDevoluciones, descripcion, 
            p.status, tipoPedido,  motivo, ruta, codPersona, date(fechaRequerida) as fechaReq, date(fechaDevoluciones) as fechaDev
            FROM
            pedidos p
                -- LEFT JOIN sae.clie01 c ON c.clave = p.cargo
            LEFT JOIN
            sistemanomina_prueba.datospersonalesempleado dp ON dp.idEmpleado = p.idSolicitante
            LEFT JOIN
            sistemanomina_prueba.datospersonalesempleado dps ON dps.idEmpleado = p.idSupervisor
                LEFT JOIN
            rechazos_pedidos r on r.idPedido = p.idPedido
            WHERE
            p.idPedido = $id;")->fetch(PDO::FETCH_ASSOC);
            $numCargo = $pedido["cargo"];
            $sucursal = $pedido["sucursal"];
            $pedido["nombreCargo"] = utf8_encode($sae->query("SELECT NOMBRE FROM CLIE01 WHERE CLAVE = '$numCargo'")->fetch(PDO::FETCH_COLUMN));
            if ($pedido['tipoPedido'] == 2 || $pedido['tipoPedido'] == 3 || $pedido['tipoPedido'] == 6) {
                if ($pedido['codPersona'] != null) {
                    $persona = $pedido['codPersona'];
                    $pedido['recibe'] = utf8_encode($sae->query("SELECT NOMBRE FROM CLIE01 WHERE CLAVE = '$persona'")->fetch(PDO::FETCH_COLUMN));
                }
            }
            $tiempo_final = microtime(true);
            // echo "PRIMERA CONSULTA " . ($tiempo_final - $tiempo_inicial);
            // echo "<br>";
            $arrayCompras = array();
            // ? -----------------------------------
            if (isset($_GET['status'])) {
                switch ($_GET['status']) {
                    case "ProcesandoDespacho":
                        $whereStatusArticulo = "and ap.STATUS in (1) ";
                        $whereStatusEspecial = "and aep.STATUS in (1) ";
                        break;
                    case "ProcesandoCompras":
                        $whereStatusArticulo = "and ap.STATUS in (2,3,4)"; //and ap.CVE_COMPRA!=''
                        $whereStatusEspecial = "and aep.STATUS in (2,3,4) "; //and aep.CVE_COMPRA!=''
                        break;
                    case "ProcesandoComprasRecibidas":
                    case "ProcesandoHerramientasRecibidas":
                        $whereStatusArticulo = "and ap.STATUS in (5,6)";
                        $whereStatusEspecial = "and aep.STATUS in (5,6)";
                        break;
                    case "ProcesandoHtaFinalizadas":
                        $whereStatusArticulo = "and ap.STATUS in (1,2,3,4,5,6,7,9)";
                        $whereStatusEspecial = "and aep.STATUS in (1,2,3,4,5,6,7,9)";
                        break;
                    case "ProcesandoHtaCancelados":
                        $whereStatusArticulo = "and ap.STATUS in (9)";
                        $whereStatusEspecial = "and aep.STATUS in (9)";
                        break;
                    default:
                        // $whereStatusArticulo = "";
                        // $whereStatusEspecial = "";
                        $whereStatusArticulo = "and ap.STATUS in (1,2,3,4,5,6,7,9)";
                        $whereStatusEspecial = "and aep.STATUS in (1,2,3,4,5,6,7,9)";
                        break;
                }
            }
            switch($sucursal){
                case '1':
                    $almacen = 1;
                    break;
                case '2':
                    $almacen = 4;
                    break;
                case '3':
                    $almacen = 7;
                    break;
            }
            if(isset($_GET['tipo'])){
                $whereTipo = "and p.tipoPedido in (2,3,6)";
                $whereInner = "inner join pedidos p on p.idPedido = ap.idPedido";
                $whereInnerEspecial = "inner join pedidos p on p.idPedido = aep.idPedido";
            }else{
                $whereTipo = "";
                $whereInner = "";
                $whereInnerEspecial = "";
            }
            // ? -----------------------------------

            $pedido['articulos'] = $pdo->query("SELECT ap.indicacion,
                ap.CVE_ART,
                 i.DESCR, 
                CANT,ENTREGADO, i.UNI_MED, ap.STATUS, csa.descripcion 
                AS DESCRIPCION, ifnull(CVE_COMPRA, '') as CVE_COMPRA, id, '0' AS ESPECIAL,
                (ifnull(cant_uno, 0) +ifnull(cant_dos,0) ) as no_disponibles,
                (i.EXIST - (ifnull(cant_uno, 0) +ifnull(cant_dos,0) )) as disponibles
                from articulos_pedidos ap
                INNER JOIN cat_status_articulo csa on csa.idStatus = ap.status $whereInner
                left join sae.inve01 i on i.cve_art = ap.CVE_ART
                left join (
                    select sum(CANT) as cant_uno, CVE_ART  from (
                            select ap.idPedido, ap.status, ap.CVE_ART, ap.CANT, p.fechaDevoluciones, 
                            concat(p.cargo,' - ',c.NOMBRE) as cargo, v.NOMBRE as nombre_vendedor
                            from articulos_pedidos ap
                            inner join pedidos p on p.idPedido = ap.idPedido 
                            inner join sae.clie01 c on c.CLAVE = p.cargo
                            inner join sae.vend01 v on c.CVE_VEND = v.CVE_VEND
                            where ap.STATUS =7 and p.tipoPedido in (2,6) and p.fechaDevoluciones!=0
                            ) p group by CVE_ART
                    ) entregados on entregados.CVE_ART = i.CVE_ART
                left join (
                    select sum(CANT) as cant_dos, CVE_ART
                    from (
                            select ap.idPedido, ap.status, ap.CVE_ART, ap.CANT, concat(nombre, ' ', apellidoPat, ' ', apellidoMat) as nombre, p.fechaDevoluciones
                            from articulos_pedidos ap
                            inner join pedidos p on p.idPedido = ap.idPedido  
                            inner join sistemanomina_prueba.datospersonalesempleado dpe on dpe.idEmpleado = p.idSolicitante
                            inner join usuarios u on u.codNoEmpleado=dpe.idEmpleado
                            where ap.STATUS =7 and p.tipoPedido in (3,6) and p.fechaDevoluciones!=0
                            ) p group by CVE_ART
                    ) hta_personal on hta_personal.CVE_ART = i.CVE_ART
                where ap.idPedido = $id
                $whereStatusArticulo
                $whereTipo;")->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($pedido['articulos'] as $index => $art) {
                // $folio = $art["CVE_COMPRA"];
                // $codigo = $art["CVE_ART"];
                // $art["NUM_PAR"] = $sae->query("SELECT x.NUM_PAR FROM PAR_COMPO01 x WHERE CVE_DOC  = '$folio' and CVE_ART='$codigo'")->fetch(PDO::FETCH_COLUMN);
                // // $art["DESCR"] = $sae->query("SELECT DESCR FROM INVE01 x WHERE CVE_ART = '$codigo'")->fetch(PDO::FETCH_COLUMN);

                // $arrayCompras[] = "p," . $art['id'];

                // $temp = $sae->query("SELECT coalesce(EXIST, 0) as EXIST
                //     FROM MULT01 WHERE CVE_ART = '$codigo' and CVE_ALM = $almacen")->fetch(PDO::FETCH_COLUMN);
                // $pedido['articulos'][$index]['EXIST'] = ($temp!=false)? $temp: 0;

                // $temp = $sae->query("SELECT coalesce(CTRL_ALM, 0) AS CTRL_ALM
                // FROM MULT01 WHERE CVE_ART = '$codigo' and CVE_ALM = $almacen")->fetch(PDO::FETCH_COLUMN);
                // $pedido['articulos'][$index]['CTRL_ALM'] = ($temp!=false)? $temp: "";
                // echo $pedido[$index];
                // $codigo = $art["CVE_ART"];
                
                $query = "SELECT m.EXIST, m.CTRL_ALM,  iif(m.STOCK_MIN > 0, 'STOCK', 'SIN STOCK') as TIPO
                    FROM MULT01 m
                WHERE m.CVE_ART = ".$art["CVE_ART"]." and m.CVE_ALM = $almacen";
                $temp = $sae->query($query)->fetch(PDO::FETCH_ASSOC);
                if($temp){
                    $pedido['articulos'][$index] = array_merge($pedido['articulos'][$index], $temp);
                }

                // $existencias = $sae->query("SELECT M.CVE_ART, iif(SUM(M.STOCK_MIN) > 0, 'STOCK', 'SIN STOCK') as TIPO,
                // SUM(CASE WHEN M.CVE_ALM = 1 THEN M.EXIST ELSE 0 END) AS STOCKNLD,
                // SUM(CASE WHEN M.CVE_ALM = 4 THEN M.EXIST ELSE 0 END) AS STOCKREY,
                // SUM(CASE WHEN M.CVE_ALM = 7 THEN M.EXIST ELSE 0 END) AS STOCKMTY
                // FROM mult01 m
                // WHERE CVE_ART = '$codigo'
                // GROUP BY CVE_ART")->fetch(PDO::FETCH_ASSOC);
                // if ($existencias) {
                //     $pedido['articulos'][$index]["STOCK_NLD"] = $existencias['STOCKNLD'];
                //     $pedido['articulos'][$index]["STOCK_REY"] = $existencias['STOCKREY'];
                //     $pedido['articulos'][$index]["STOCK_MTY"] = $existencias['STOCKMTY'];
                //     $pedido['articulos'][$index]["TIPO"] = $existencias['TIPO'];
                //     // if (isset($_GET['scanner'])) {
                //     //     $pedido['articulos'][$index]["CTRL_ALM"] = $sae->query("SELECT CTRL_ALM from MULT01 where CVE_ART = '$codigo' AND CVE_ALM = 4")->fetch(PDO::FETCH_COLUMN);
                //     // }
                // } else {
                //     $pedido['articulos'][$index]["STOCK_NLD"] = 0;
                //     $pedido['articulos'][$index]["STOCK_REY"] = 0;
                //     $pedido['articulos'][$index]["STOCK_MTY"] = 0;
                //     // if (isset($_GET['scanner'])) {
                //         $pedido['articulos'][$index]["CTRL_ALM"] = "";
                //     // }
                //     $pedido['articulos'][$index]["TIPO"] = "SIN STOCK";
                // }
            }

            $tiempofinalarticulos = microtime(true);

            $pedido['especiales'] = $pdo->query("SELECT aep.* ,csa.descripcion AS DESCRIPCION, idEspecial as id, '1' as ESPECIAL
            FROM articulos_especiales_pedidos aep 
            INNER JOIN cat_status_articulo csa on csa.idStatus = aep.status
            $whereInnerEspecial
            WHERE aep.idPedido = $id
            $whereStatusEspecial
            $whereTipo")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pedido['especiales'] as $index => $especial) {
                $arrayCompras[] = "e," . $especial['id'];
                if ($especial['NUM_PAR'] && $especial['CVE_COMPRA']) {
                    $folioCompra = $especial['CVE_COMPRA'];
                    $numPar = $especial['NUM_PAR'];
                    $pedido['especiales'][$index]['CVE_ART'] = $sae->query("SELECT CVE_ART FROM PAR_COMPO01 WHERE CVE_DOC = '$folioCompra' and NUM_PAR = $numPar")->fetch(PDO::FETCH_COLUMN);
                } else {
                    $pedido['especiales'][$index]['CVE_ART'] = "";
                }
                $pedido['especiales'][$index]['ESPECIAL'] = 1;
            }
            $tiempofinalespeciales = microtime(true);
            if (!empty($arrayCompras)) {
                $stringCompras = implode("','", $arrayCompras);
                // Se trae lo de COMPC porque tiene que se rlo recibido.
                $pedido['compras'] = $sae->query("SELECT DISTINCT CLAVE_DOC as CVE_COMPRA FROM PAR_COMPC_CLIB01 pcc WHERE CAMPLIB1 IN ('$stringCompras');")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $pedido['compras'] = [];
            }
            foreach ($pedido['compras'] as $index => $c) {
                $idCompra = $c['CVE_COMPRA'];
                $firma = $pdo->query("SELECT idPedido from entregas_compras where idPedido = $id and idCompra = '$idCompra';")->fetch(PDO::FETCH_COLUMN);
                if ($firma) {
                    $pedido['compras'][$index]['FIRMADO'] = true;
                } else {
                    $pedido['compras'][$index]['FIRMADO'] = false;
                }
            }
            $tiempofolios = microtime(true);
            // echo "folios CONSULTA " . ($tiempofolios - $tiempofinalespeciales);
            // echo "<br>";
            echo json_encode($pedido);
            exit;
        }

        if(isset($_GET['idPedidoFolios'])){
            $id = $_GET['idPedidoFolios'];

            $pedido['folios'] = $pdo->query("SELECT 
                idPedido,
                folio,
                surtidor,
                recibe AS receptor,
                fechaEntrega,
                (SELECT 
                        COUNT(cve_art)
                    FROM
                        articulos_pedidos
                    WHERE
                        FOLIO = f.folio) AS cantidad
                FROM folios f
            WHERE idPedido = $id;")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($pedido);
            exit;
        }

        if (isset($_GET['folio'])) {
            $folio = $_GET['folio'];
            $articulos = $pdo->query("SELECT if(i.DESCR is null, null, ap.CVE_ART) as CVE_ART, ifnull(i.DESCR, ap.CVE_ART) as DESCR, CANT, i.UNI_MED, csa.descripcion AS STATUS
            from articulos_pedidos ap
            INNER JOIN cat_status_articulo csa on csa.idStatus = ap.status
            left join sae.inve01 i on i.cve_art = ap.CVE_ART
            where ap.FOLIO = '$folio';")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["articulos" => $articulos]);
            exit;
        }
        if (isset($_GET['herramientas'])) {
            $pedidos = $pdo->query("SELECT 
            idPedido,
            cargo,
            c.nombre,
            descripcion,
            indicacionAdicional,
            idSolicitante,
            concat(dpr.apellidoPat, ' ', dpr.apellidoMat, ' ', dpr.nombre) as nombreSolicitante,
            idSupervisor,
            concat(dps.apellidoPat, ' ', dps.apellidoMat, ' ', dps.nombre) as nombreSupervisor,
            DATE(fechaRequerida) AS fechaRequerida,
            TIME(fechaRequerida) AS horaRequerida,
            fechaDevoluciones,
            fechaSolicitud,
            p.status,
            tipoPedido
            FROM
            pedidos p
            inner join sae.clie01 c on c.CLAVE = cargo
            inner join sistemanomina_prueba.datospersonalesempleado dpr on dpr.idEmpleado = idSolicitante
            inner join sistemanomina_prueba.datospersonalesempleado dps on dps.idEmpleado = idSupervisor
            WHERE
            p.status = 2 and tipoPedido = 4 and idSolicitante = $codNoEmpleado or idSupervisor = $codNoEmpleado")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($pedidos);
            exit;
        }

        if (isset($_GET['sucursal'])) {
            $sucursal = $_GET['sucursal'];
            $pedidos = $pdo->query("SELECT idPedido as CVE_DOC, concat(cargo, ' - ', c.NOMBRE) as NOMBRE, concat(apellidoPat, ' ', apellidoMat, ' ', dp.nombre) as CVE_CLPV, tipoPedido, p.status 
            from pedidos p 
            left join sistemanomina_prueba.datospersonalesempleado dp on dp.idEmpleado = idSupervisor 
            left join sae.clie01 c on c.CLAVE = p.cargo 
            where  p.status in(2,4) and tipoPedido in (1,2,3,6) and p.sucursal = $sucursal")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pedidos as $index => $ped) {
                $pedidos[$index] = array_map('utf8_encode', $ped);
            }
            echo json_encode($pedidos);
            exit;
        }


        throw new Exception("No se especifico ningun metodo para el envio de la informacion.");
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(["error" => true, 
                        "msg" => $e->getMessage(), 
                        "ScriptCausa" => $e->getFile(),
                        "LineaCausa" => $e->getLine(),
                        "CadenaInformatica" => $e->__toString()]);
        exit;
    }
}
// Crear un nuevo post
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $notificacion = new Notificaciones();
        $pdo->beginTransaction();
        $_POST = json_decode(file_get_contents('php://input'), true);
        if ($_POST == "") {
            parse_str(file_get_contents('php://input'), $_POST);
        }
        $cargo = $_POST['cargo'];
        $idSolicitante = str_pad($_POST['idSolicitante'], 5, "0", STR_PAD_LEFT);
        $idSupervisor = str_pad($_POST['idSupervisor'], 5, "0", STR_PAD_LEFT);
        $fechaRequerida = $_POST['fechaRequerida'];
        $fechaDevoluciones = $_POST['fechaDevolucion'];
        $listaArticulos = isset($_POST['articulos']) ? $_POST['articulos'] : [];
        $listaEspeciales = isset($_POST['especiales']) ? $_POST['especiales'] : [];
        $descripcion = $_POST['descripcion'];
        $indicaciones = $_POST['indicaciones'];
        $tipoPedido = $_POST['tipo'];
        $ruta = $_POST['ruta'];
        $status = 1;
        $sucursal = isset($_COOKIE['sucursal'])?$_COOKIE['sucursal']:$pdo->query("SELECT sucursal FROM pedidosalmacen.usuarios WHERE codNoEmpleado = '$idSupervisor'")->fetch(PDO::FETCH_COLUMN);
        // Si el solicitante es supervisor, entonces el status pasa directamente a aprovado.

        $codPersona = (isset($_POST['codPersona']))?$_POST['codPersona']:$cargo;
        $planta = (isset($_POST['planta']))?$_POST['planta']:"";
        $caja_gral_o_hta_vehiculo = (isset($_POST['caja_gral_o_hta_vehiculo']))?$_POST['caja_gral_o_hta_vehiculo']:"";

        if ($idSolicitante == $idSupervisor) {
            $status = 2;
        }
        $codTipo = "null";
        if ($tipoPedido == 3 || $tipoPedido == 2 || $tipoPedido == 6) {
            $codTipo = "'" . $codPersona . "'";
        }
        $pedidos = $pdo->query("SELECT * from pedidos;")->fetchAll(PDO::FETCH_ASSOC);
        // echo "INSERT INTO pedidos (cargo, descripcion, indicacionAdicional, idSolicitante, idSupervisor, fechaRequerida, fechaDevoluciones, ruta, status, tipoPedido, codPersona) VALUES ('$cargo', '$descripcion', '$indicaciones', $idSolicitante, $idSupervisor, '$fechaRequerida', '$fechaDevoluciones', $ruta, $status, $tipoPedido, $codTipo)";
        $pdo->exec("INSERT INTO pedidos (cargo, sucursal, descripcion, indicacionAdicional, idSolicitante, idSupervisor, fechaRequerida, fechaDevoluciones, ruta, status, tipoPedido, codPersona, planta, caja_gral_O_hta_vehiculo) 
        VALUES ('$cargo','$sucursal', '$descripcion', '$indicaciones', $idSolicitante, $idSupervisor, '$fechaRequerida', '$fechaDevoluciones', $ruta, $status, $tipoPedido, $codTipo, '$planta', '$caja_gral_o_hta_vehiculo')");
        $idPedido = $pdo->lastInsertId();
        foreach ($listaArticulos as $articulo) {
            $fechaArticulo = isset($articulo["FECHA"]) ? $articulo["FECHA"] : $fechaRequerida; 
            $indicacion = isset($articulo["INDICACION"]) ? $articulo["INDICACION"] : 0; 
            $query = "INSERT INTO articulos_pedidos (idPedido, CVE_ART, CANT, STATUS, fechaRequerida, indicacion) VALUES ($idPedido, '" . $articulo["CVE_ART"] . "' , " . $articulo["CANT"] . ", 1, '$fechaArticulo', $indicacion);";
            $pdo->exec($query);
        }
        foreach ($listaEspeciales as $articulo) {
            $descripcion = $articulo["DESCR"];
            $cant = $articulo["CANT"];
            $uni_med = $articulo["UNI_MED"];
            $marca = $articulo["MARCA"];
            $catalogo = $articulo["CATALOGO"];
            $url = $articulo["URL"];
            $fechaEspecial = isset($articulo["FECHA"]) ? $articulo["FECHA"] : $fechaRequerida; 
            if (isset($articulo['ULT_COSTO'])) {
                $ult_cost = $articulo['ULT_COSTO'];
            } else {
                $ult_cost = 0;
            }
            $query = "INSERT into articulos_especiales_pedidos (idPedido, DESCR, CANT, UNI_MED, MARCA, CATALOGO, URL, STATUS, ULT_COSTO, fechaRequerida) VALUES ($idPedido, '$descripcion', $cant, '$uni_med', '$marca', '$catalogo' ,'$url', 1, $ult_cost, '$fechaEspecial')";
            $pdo->exec($query);
        }
        http_response_code(200);
        // $nombre = $pdo->query("SELECT concat(nombre, ' ', apellidoPat, ' ', apellidoMat) from sistemanomina_prueba.datospersonalesempleado where idEmpleado = " . $_POST['idSolicitante'])->fetch(PDO::FETCH_COLUMN);
        $datosNotificacion = ["idPedido" => $idPedido, "cargo" => $_POST["cargo"], "nombre" => $nombre, "fechaRequerida" => $_POST['fechaRequerida'], "descripcion" => $descripcion, "articulos" => $listaArticulos];
        if ($status == 1) {
            $notificacion->mandarNotificacion("Nuevo Pedido!", "$nombre ha realizado un nuevo pedido, presiona para ver el pedido.", $idSupervisor, $datosNotificacion, $notificacion::AUTORIZACION);
        } else {
            $notificacion->mandarNotificacion("Nuevo Pedido!", "Hay un nuevo pedido", "Almacen", [], $notificacion::ALMACEN);
        }

        $pdo->commit();
        echo json_encode(["error" => false, "idPedido" => $idPedido]);
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(["error" => true, "msg" => $e->getMessage(), "linea" => $e->getLine()]);
        http_response_code(400);
        exit;
    }
    /////////////////////////////////////////////////////////////////////////////////////////////////////////////

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////
}
//Borrar
if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $id = $_GET['id'];
    $pdo->exec("DELETE FROM pedidos WHERE idPedido = $id");
    echo "hecho";
    exit;
}
//Actualizar
if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $_DATA = json_decode(file_get_contents('php://input'), true);
    if ($_DATA == "") {
        parse_str(file_get_contents('php://input'), $_DATA);
    }

    if(isset($_GET['partidas'])){
        try{
            $pdo->beginTransaction();
    
            $idPedido = $_DATA['idPedido'];
            $fechaRequerida = $_DATA['fechaRequerida'];
            $cargo = $_DATA['cargo'];
            $pdo->exec("UPDATE pedidos
                SET cargo='$cargo', fechaRequerida='$fechaRequerida'
                WHERE idPedido=$idPedido");

            $listaArticulos = isset($_DATA['articulos']) ? $_DATA['articulos'] : [];
            $listaEspeciales = isset($_DATA['especiales']) ? $_DATA['especiales'] : [];
            $pdo->exec("DELETE FROM articulos_pedidos where idPedido = $idPedido");
            $pdo->exec("DELETE FROM articulos_especiales_pedidos where idPedido = $idPedido");
            foreach ($listaArticulos as $articulo) {
                $fechaArticulo = isset($articulo["FECHA"]) ? $articulo["FECHA"] : $fechaRequerida; 
                $indicacion = isset($articulo["INDICACION"]) ? $articulo["INDICACION"] : 0; 
                $query = "INSERT INTO articulos_pedidos (idPedido, CVE_ART, CANT, STATUS, fechaRequerida, indicacion) VALUES ($idPedido, '" . $articulo["CVE_ART"] . "' , " . $articulo["CANT"] . ", 1, '$fechaArticulo', $indicacion);";
                $pdo->exec($query);
            }
            foreach ($listaEspeciales as $articulo) {
                $descripcion = $articulo["DESCR"];
                $cant = $articulo["CANT"];
                $uni_med = $articulo["UNI_MED"];
                $marca = $articulo["MARCA"];
                $catalogo = $articulo["CATALOGO"];
                $url = $articulo["URL"];
                $fechaEspecial = isset($articulo["FECHA"]) ? $articulo["FECHA"] : $fechaRequerida; 
                if (isset($articulo['ULT_COSTO'])) {
                    $ult_cost = $articulo['ULT_COSTO'];
                } else {
                    $ult_cost = 0;
                }
                $query = "INSERT into articulos_especiales_pedidos (idPedido, DESCR, CANT, UNI_MED, MARCA, CATALOGO, URL, STATUS, ULT_COSTO, fechaRequerida) VALUES ($idPedido, '$descripcion', $cant, '$uni_med', '$marca', '$catalogo' ,'$url', 1, $ult_cost, '$fechaEspecial')";
                $pdo->exec($query);
            }
            
            $pdo->commit();
            echo json_encode(["error" => false]);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(["error" => true, "msg" => $e->getMessage(), "linea" => $e->getLine()]);
            http_response_code(400);
            exit;
        }
    }

    if(isset($_GET['traspaso'])){
        try{
            $pdo->beginTransaction();
            $id_devolucion = $_DATA['id_devolucion'];
            $id_pedido = $_DATA['id_pedido'];
            $id_articulo = $_DATA['clave'];
            $cantidad_original = $_DATA['cantidad_original'];
            $supervisor_original = $_DATA['supervisor_original'];
            $cantidad = $_DATA['cantidad'];
            $supervisor = $_DATA['supervisor'];
            $fechaDevoluciones = $_DATA['fecha_dev'];
            $fechaRequerida = $_DATA['fecha_req'];
            $cargo = $_DATA['cargo'];
            
            // crear una nueva devolucion
            $pdo->exec("INSERT INTO devoluciones (cargo, fechaSolicitud, fechaRecoleccion,      status,     autorizo,          solicito)
                                        VALUES ('$cargo', '$fechaRequerida', '$fechaDevoluciones', 0, $supervisor, $supervisor)");

            $id_nueva_devolucion = $pdo->query("SELECT idDevolucion 
                FROM pedidosalmacen.devoluciones
                order by idDevolucion desc
                limit 1;")->fetch(PDO::FETCH_COLUMN);
            
            // crear nuevo articulo devoluciones
            $pdo->exec("INSERT INTO articulos_devoluciones
                    (idDevolucion,              idPedido,  CVE_ART,         cant,    cant_devuelta, estado_devolucion) 
            VALUES ($id_nueva_devolucion,  $id_pedido,  '$id_articulo',   $cantidad,    0,    1 )");

            // actualizar devolucion setear a 2 si se traspasoron todas o restar de cant las traspasadas
            if($cantidad_original == $cantidad){
                $pdo->query("UPDATE articulos_devoluciones
                SET  estado_devolucion =  2, cant_devuelta = $cantidad_original
                WHERE idDevolucion = $id_devolucion and idPedido = $id_pedido and CVE_ART = $id_articulo");
            }
            if($cantidad_original>$cantidad){
                $pdo->query("UPDATE articulos_devoluciones
                SET  cant_devuelta = ($cantidad_original - $cantidad)
                WHERE idDevolucion = $id_devolucion and idPedido = $id_pedido and CVE_ART = $id_articulo"); 
            }

            // enviar correos
            // $correos = array("herramientas.rey@cebsa.mx", "cguzman@cebsa.mx");
            // foreach ($correos as $index => $correo) {
            //     // correo_traspaso($pdo, $correo, $id, 3);
            // }

            http_response_code(200);
            $pdo->commit();
            echo json_encode(["error" => false]);
            exit;

        }catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(["error" => true, "msg" => $e->getMessage(), "linea" => $e->getLine()]);
            exit;
        }
    }

    if(isset($_GET['comprimir'])){
        // $id = $_GET['comprimir'];
        // $firma = $pdo->query("SELECT 
        //     firmasurtidor
        //     FROM folios
        // WHERE folio = '$id'")->fetch(PDO::FETCH_COLUMN);

        try{
            $pdo->beginTransaction();

            $folios = $pdo->query("SELECT folio, firmasurtidor, firmarecibe 
            from folios  where firmarecibe is not null and firmasurtidor is not null
            and path_firma_receptor is null")->fetchAll(PDO::FETCH_ASSOC);
    
            foreach ($folios as $index => $folio) {
                $id = $folio['folio'];
                $path_surtidor = "../imagenes/folios/firma_surtidor/".$id.".png";
                $path_receptor = "../imagenes/folios/firma_recibio/".$id.".png";

                $data = base64_decode($folio['firmasurtidor']);
                $myfile = fopen($path_surtidor, "w");
                fwrite($myfile, $data);
                fclose($myfile);

                $data = base64_decode($folio['firmarecibe']);
                $myfile = fopen($path_receptor, "w");
                fwrite($myfile, $data);
                fclose($myfile);

                $pdo->exec("UPDATE folios set
                    path_firma_surtidor = '$path_surtidor' ,
                    path_firma_receptor = '$path_receptor'
                WHERE folio = '$id'");
            }

            $pdo->commit();

        }catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(["error" => true, "msg" => $e->getMessage()]);
            exit;
        }




        http_response_code(200);
        echo json_encode(["message" =>"Hecho"]);            
        exit;
    }

    if (isset($_GET['fechaDevolucion'])) {
        try {
            $pdo->beginTransaction();
            $notificacion = new Notificaciones();
            $_PUT = json_decode(file_get_contents('php://input'), true);
            if ($_PUT == "") {
                parse_str(file_get_contents('php://input'), $_PUT);
            }
            $id = $_PUT['idPedido'];
            $fecha = $_PUT['fecha'];
            $pdo->exec("UPDATE pedidos SET fechaDevoluciones = '$fecha' WHERE idPedido = $id");
            // $notificacion->mandarNotificacion("Fecha requerida modificada", "Se modificó la fecha requerida del pedido $id", "Almacen", [], $notificacion::ALMACEN);
            $pdo->commit();
            echo json_encode(["error" => false]);
            exit;
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(["error" => true, "msg" => $e->getMessage()]);
            exit;
        }
    }

    if (isset($_GET['fechaRequerimiento'])) {
        try {
            $pdo->beginTransaction();
            $notificacion = new Notificaciones();
            $_PUT = json_decode(file_get_contents('php://input'), true);
            if ($_PUT == "") {
                parse_str(file_get_contents('php://input'), $_PUT);
            }
            $id = $_PUT['idPedido'];
            $fecha = $_PUT['fecha'];
            $pdo->exec("UPDATE pedidos SET fechaRequerida = '$fecha' WHERE idPedido = $id");
            // $notificacion->mandarNotificacion("Fecha requerida modificada", "Se modificó la fecha requerida del pedido $id", "Almacen", [], $notificacion::ALMACEN);
            $pdo->commit();
            echo json_encode(["error" => false]);
            exit;
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(["error" => true, "msg" => $e->getMessage()]);
            exit;
        }
    }



    if (isset($_GET['aprobado'])) {
        try {
            $pdo->beginTransaction();
            $notificacion = new Notificaciones();
            $_PUT = json_decode(file_get_contents('php://input'), true);
            $id = $_PUT['idPedido'];
            $solicitante = $pdo->query("SELECT idSolicitante from pedidos where idPedido = $id")->fetch(PDO::FETCH_COLUMN);
            $pdo->exec("UPDATE pedidos
            SET
            status = 2
            WHERE idPedido = $id");
            $notificacion->mandarNotificacion("Pedido Aprobado", "Tu pedido fue APROBADO.", $solicitante, [], $notificacion::APROBADO);
            $notificacion->mandarNotificacion("Nuevo Pedido!", "Hay un nuevo pedido", "Almacen", [], $notificacion::ALMACEN);
            $pdo->commit();
            echo json_encode(["error" => false]);
            exit;
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(["error" => true, "msg" => $e->getMessage()]);
            exit;
        }
    }
    if (isset($_GET['rechazado'])) {
        try {
            $pdo->beginTransaction();
            $notificacion = new Notificaciones();
            $_PUT = json_decode(file_get_contents('php://input'), true);
            $id = $_PUT['idPedido'];
            $motivo = $_PUT['motivo'];
            $solicitante = $pdo->query("SELECT idSolicitante from pedidos where idPedido = $id")->fetch(PDO::FETCH_COLUMN);
            $pdo->exec("UPDATE pedidos
            SET
            status = 3
            WHERE idPedido = $id");
            $pdo->exec("INSERT into rechazos_pedidos (idPedido, motivo) values ($id, '$motivo')");
            $notificacion->mandarNotificacion("Pedido Rechazado", "Tu pedido fue RECHAZADO, presiona para mas detalles.", $solicitante, ["descripcion" => $_PUT['motivo']], $notificacion::RECHAZADO);
            $pdo->commit();
            echo json_encode(["error" => false]);
            exit;
        } catch (Exception $e) {
            $pdo->rollback();
            echo json_encode(["error" => true, "msg" => $e->getMessage()]);
            exit;
        }
    }

    if (isset($_GET['traspasoAlmacenRechazado'])) {
        try {
            $notificacion = new Notificaciones();
            //$sae->beginTransaction();
            $pdo->beginTransaction();
            $_PUT = json_decode(file_get_contents('php://input'), true);

            $motivo = $_PUT['motivo'];
            $idPedido = $_PUT['idPedido'];
            $origen = $_PUT['origen'];
            //$destino = $_PUT['destino'];
            $destino = "1";
            
            $destino2 = "";
            if($destino == "1"){
                $destino2 = "Nuevo Laredo";
            }
            else if($destino == "2"){
                $destino2 = "Reynosa";
            }
            else if($destino == "3"){
                $destino2 = "Monterrey";
            }



            $pdo->exec("UPDATE traspasos_almacen set motivo_rechazo = '$motivo', autorizado = '0', rechazado = '1' where idPedido = $idPedido");
            $pdo->exec("UPDATE articulos_pedidos set status = 1 where idPedido = $idPedido and status = 17");
            $pdo->exec("UPDATE articulos_especiales_pedidos set status = 1 where idPedido = $idPedido and status = 17");


            //$notificacion->mandarNotificacion("Traspaso Rechazado", "Tu traspaso fue RECHAZADO, presiona para mas detalles.", $solicitante, ["descripcion" => $_PUT['motivo']], $notificacion::RECHAZADO);
            $notificacion->mandarNotificacion("Traspaso fue Rechazado #$idPedido", "Desliza para mas detalles", "Almacen", ["origen" => "$origen", "descripcion" => "Tu traspaso hacia $destino2 fue Rechazado \n\nMotivo: $motivo"], $notificacion::RECHAZADO_TRASPASO_ALMACEN);

            $pdo->commit();
            //$sae->commit();
            echo json_encode(["msg" => "El traspaso se rechazo correctamente."]);
            exit;
        } catch (Exception $e) {
            $sae->rollBack();
            $pdo->rollBack();
            //echo json_encode(["error" => true, "msg" => $e->getMessage(), "linea" => $e->getLine()]);
            exit;
        }
    }

    if (isset($_GET['traspasoAlmacenAutorizado'])) {
        try {
            $notificacion = new Notificaciones();
            //$sae->beginTransaction();
            $pdo->beginTransaction();
            $_PUT = json_decode(file_get_contents('php://input'), true);

            $firma = $_PUT['firma'];
            $idPedidoOriginal = $_PUT['idPedido'];
            $origen = $_PUT['origen'];
            $destino = $_PUT['destino'];

            
            $destino2 = "";
            if($destino == "1"){
                $destino2 = "Nuevo Laredo";
            }
            if($destino == "2"){
                $destino2 = "Reynosa";
            }
            if($destino == "3"){
                $destino2 = "Monterrey";
            }

            //$pdo->exec("INSERT INTO pedidos (cargo, sucursal, descripcion, indicacionAdicional, idSolicitante, idSupervisor, fechaRequerida, fechaDevoluciones, ruta, status, tipoPedido, codPersona, planta, caja_gral_O_hta_vehiculo) 
            //VALUES ('$cargo','$sucursal', '$descripcion', '$indicaciones', $idSolicitante, $idSupervisor, '$fechaRequerida', '$fechaDevoluciones', $ruta, $status, $tipoPedido, $codTipo, '$planta', '$caja_gral_o_hta_vehiculo')");
            //$idPedido = $pdo->lastInsertId();

            $pdo->exec("INSERT INTO pedidos SELECT NULL idPedido, '$destino' sucursal,  cargo, descripcion, indicacionAdicional, idSolicitante, idSupervisor, fechaRequerida, fechaDevoluciones, fechaCierre, ruta, status, tipoPedido, fechaSolicitud, codPersona, idTraspaso, isDepurado, planta, caja_gral_O_hta_vehiculo, traspaso 
            FROM pedidos WHERE idPedido = $idPedidoOriginal;'");
            $idPedido = $pdo->lastInsertId();

            $pdo->exec("UPDATE traspasos_almacen set firma_autorizado = '$firma', autorizado = '1', rechazado = '0' where idPedido = $idPedidoOriginal");
            $pdo->exec("UPDATE articulos_pedidos set idPedido = $idPedido, status = 1 where idPedido = $idPedidoOriginal and status = 17");
            $pdo->exec("UPDATE articulos_especiales_pedidos set idPedido = $idPedido, status = 1 where idPedido = $idPedidoOriginal and status = 17");


            //$notificacion->mandarNotificacion("Traspaso Rechazado", "Tu traspaso fue RECHAZADO, presiona para mas detalles.", $solicitante, ["descripcion" => $_PUT['motivo']], $notificacion::RECHAZADO);
            $notificacion->mandarNotificacion("Traspaso fue Aprobado #$idPedidoOriginal", "Desliza para mas detalles", "Almacen", ["origen" => "$origen", "descripcion" => "Tu traspaso hacia $destino2 fue Aprobado \n\nDetalles: Los articulos traspasados de este pedido ahora seran manejados por $destino2"], $notificacion::APROBADO_TRASPASO_ALMACEN);

            $pdo->commit();
            //$sae->commit();
            echo json_encode(["msg" => "El traspaso se rechazo correctamente."]);
            exit;
        } catch (Exception $e) {
            $sae->rollBack();
            $pdo->rollBack();
            //echo json_encode(["error" => true, "msg" => $e->getMessage(), "linea" => $e->getLine()]);
            exit;
        }
    }

    if (isset($_GET['cerrado'])) {
        try {
            $pdo->beginTransaction();
            $_PUT = json_decode(file_get_contents('php://input'), true);
            if ($_PUT == "") {
                parse_str(file_get_contents('php://input'), $_PUT);
            }
            // $_PUT = json_decode(file_get_contents('php://input'), true);
            $id = $_PUT['idPedido'];
            $fecha = date('Y-m-d');
            $pdo->exec("UPDATE pedidos set STATUS = 9, fechaCierre = '$fecha' where idPedido = $id");
            echo json_encode(["msg" => "Pedido finalizado correctamente"]);
            $pdo->commit();
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(["error" => true, "msg" => $e->getMessage(), "linea" => $e->getLine()]);
            exit;
        }
    }
    //! Esto no modifica, pero fue necesario xq en el get no le puedo mandar los parametros.
    if (isset($_GET['almacen'])) {
        // $sucursal = $_GET['sucurusal'];
        $sucursal =  isset($_GET['sucursal']) ?  $_GET['sucursal'] :  2;
        $fechaActual = date("Y-m-d");
        $_DATA = json_decode(file_get_contents('php://input'), true);

        $whereStatus = "p.status in (2,4,5,6,7,8) and";
        $whereTipo = "tipoPedido in (1,2,3,4,6)";
        $whereOrden = "";
        $whereTiempo = "";
        $fecha = "";
        // if (isset($_GET["jefeAlmacen"]) || isset($_GET["admin"])) {
        //     $whereTipo = "tipoPedido in (1,2,3,4)";
        // }
        if (isset($_DATA['status']) || isset($_GET['status'])) {
            $status = isset($_DATA['status']) ? $_DATA['status'] : $_GET['status'];
            switch ($status) {
                case "Finalizados":
                    $fecha = "case 
                    when date(fechaCierre) = date(p.fechaRequerida) then 0 
                    when date(fechaCierre) > date(p.fechaRequerida) then -1
                    when date(fechaCierre) < date(p.fechaRequerida) then 1 
                    end as tiempoEntrega,";
                    $whereStatus = "(p.status = 9 or p.status = 10) and";
                    break;
                case "Procesando":
                    $fecha = "case 
                    when date(now()) = date(p.fechaRequerida) then 0 
                    when date(now()) > date(p.fechaRequerida) then -1
                    when date(now()) < date(p.fechaRequerida) then 1 
                    end as tiempoEntrega,";
                    $whereStatus = "p.status != 9 and p.status != 3 and p.status != 10 and";
                    break;
            }
        }
        if (isset($_DATA['tipo'])) {
            switch ($_DATA['tipo']) {
                case "Material para Obra":
                    $whereTipo = "1";
                    break;
                case "Herramienta Mayor":
                    $whereTipo = "2";
                    break;
                case "Herramienta Personal":
                    $whereTipo = "3";
                    break;
                case "Traspasos":
                    $whereTipo = "4";
                    break;
            }
        }
        if (isset($_DATA['orden'])) {
            switch ($_DATA['orden']) {
                case "Mas antiguos primero":
                    $whereOrden = "ORDER BY p.fechaRequerida asc";
                    break;
                case "Mas recientes primero":
                    $whereOrden = "ORDER BY p.fechaRequerida desc";
                    break;
            }
        }
        if (isset($_DATA['tiempo'])) {
            switch ($_DATA['tiempo']) {
                case "A tiempo":
                    $whereTiempo = "date(p.fechaRequerida) > '$fechaActual' and";
                    break;
                case "Hoy":
                    $whereTiempo = "date(p.fechaRequerida) = '$fechaActual' and";
                    break;
                case "Atrasado":
                    $whereTiempo = "date(p.fechaRequerida) < '$fechaActual' and";
                    break;
            }
        }
        $pedidos = $pdo->query("SELECT 
        p.idPedido,
        cargo,
        c.nombre,
        descripcion,
        indicacionAdicional,
        idSolicitante,
        concat(dpr.apellidoPat, ' ', dpr.apellidoMat, ' ', dpr.nombre) as nombreSolicitante,
        idSupervisor,
        concat(dps.apellidoPat, ' ', dps.apellidoMat, ' ', dps.nombre) as nombreSupervisor,
        DATE(p.fechaRequerida) AS fechaRequerida,
        TIME(p.fechaRequerida) AS horaRequerida,
        fechaDevoluciones,
        fechaCierre,
        p.isDepurado,
        fechaSolicitud,
        $fecha
        p.status,
        tipoPedido,
        ruta,
        ifnull(pendientes.pendientes, 0) as artPendientes,
        ifnull(compras.encompra, 0) as artEnCompra
        -- ,concat(dpe.nombre, ' ', dpe.apellidoPat, ' ', dpe.apellidoMat) as nombreComprador
        FROM
        pedidosalmacen.pedidos p
        -- inner join articulos_pedidos ap on ap.idPedido = p.idPedido
        -- inner join compras c on c.idCompra = ap.CVE_COMPRA
        -- inner join sistemanomina_prueba.datospersonalesempleado dpe on dpe.idEmpleado = c.comprador 
        left join sae.clie01 c on c.CLAVE = cargo
        left join sistemanomina_prueba.datospersonalesempleado dpr on dpr.idEmpleado = idSolicitante
        left join sistemanomina_prueba.datospersonalesempleado dps on dps.idEmpleado = idSupervisor
        left join (SELECT idPedido, count(idPedido) as pendientes  FROM pedidosalmacen.articulos_pedidos where status in(1) GROUP BY idPedido) as pendientes on pendientes.idPedido = p.idPedido
        left join (select count(idPedido) as encompra, idPedido from (select idPedido, status from articulos_pedidos where status in(2,3,4) union select idPedido, status from articulos_especiales_pedidos where status in(2,3,4)) p group by idPedido) compras on compras.idPedido = p.idPedido
        WHERE $whereStatus $whereTipo and p.sucursal = $sucursal")->fetchAll(PDO::FETCH_ASSOC);

        // foreach ($pedidos as $index => $pedido) {
        //     $idPedido = $pedido["idPedido"];
        //     // $pendientes = $pdo->query("SELECT count(idPedido) as pendientes  FROM pedidosalmacen.articulos_pedidos where idPedido = '$idPedido' and status in(1)")->fetch(PDO::FETCH_COLUMN);
        //     $encompraN = $pdo->query("SELECT count(idPedido) as encompra FROM pedidosalmacen.articulos_pedidos where idPedido = '$idPedido' and status in(2,3,4)")->fetch(PDO::FETCH_COLUMN);
        //     $encompraE = $pdo->query("SELECT count(idPedido) as encompra FROM pedidosalmacen.articulos_especiales_pedidos where idPedido = '$idPedido' and status in(2,3,4)")->fetch(PDO::FETCH_COLUMN);
        //     $pedidos[$index]["artPendientes"] = $pendientes;
        //     $pedidos[$index]["artEnCompra"] = $encompraN + $encompraE;
        //     // $articulos = $pdo->query("SELECT CVE_ART from articulos_pedidos where idPedido = $idPedido")->fetchAll(PDO::FETCH_COLUMN);
        //     // $stringArticulos = implode("','", $articulos);
        //     // if($sae->query("SELECT SUM(STOCK_MIN) FROM mult01 where CVE_ART in ('$stringArticulos')")->fetch(PDO::FETCH_COLUMN) > 0){
        //     //     $pedidos[$index]["stock"] = "STOCK";
        //     // }else{
        //     //     $pedidos[$index]["stock"] = "SIN STOCK";
        //     // }
        // }

        echo json_encode(["pedidos" => $pedidos]);
        exit;
    }
    if (isset($_GET['almacenPrueba'])) {
        $sucursal = isset($_GET['sucursal'])?$_GET['sucursal']:$_COOKIE['sucursal'];
        $fechaActual = date("Y-m-d");
        $_DATA = json_decode(file_get_contents('php://input'), true);
        // $sucursal = 2;
        $sql_min_max = "";
        if(isset($_GET['min']) and $_GET['max']){
            $min = $_GET['min'];
            $max = $_GET['max'];
            $sql_min_max = "and '$min' < p.fechaRequerida and p.fechaRequerida  < '$max'";
        }



        $whereStatus = "p.status in (2,4,5,6,7,8) and";
        $whereTipo = "tipoPedido in (1)";
        $whereOrden = "";
        $whereTiempo = "";
        $fecha = "";
        if (isset($_GET["jefeAlmacen"]) || isset($_GET["admin"])) {
            $whereTipo = "tipoPedido in (1,2,3,4)";
        }
        if (isset($_DATA['status']) || isset($_GET['status'])) {
            $status = isset($_DATA['status']) ? $_DATA['status'] : $_GET['status'];
            $fecha = "case 
            when date(now()) = date(p.fechaRequerida) then 0 
            when date(now()) > date(p.fechaRequerida) then -1
            when date(now()) < date(p.fechaRequerida) then 1 
            end as tiempoEntrega,";
            $whereStatus = "p.status != 9 and p.status != 3 and p.status != 10 and";
            $leftJoinSelect = "";
            $leftJoin = "";
            switch ($status) {
                case "Finalizados":
                    $fecha = "case 
                    when date(fechaCierre) = date(p.fechaRequerida) then 0 
                    when date(fechaCierre) > date(p.fechaRequerida) then -1
                    when date(fechaCierre) < date(p.fechaRequerida) then 1 
                    end as tiempoEntrega,";
                    $whereStatusArticulo="";
                    $whereStatus = "(p.status = 3 or p.status = 9 or p.status = 10) and";
                    break;
                case "ProcesandoDespacho":
                    $whereStatusArticulo="and pendientes>=1";
                    break;
                case "ProcesandoCompras":
                    $whereStatusArticulo="and encompra>=1";
                    break;
                case "ProcesandoComprasRecibidas":
                    $whereStatusArticulo="and enrecibo >= 1";
                    // $whereStatus = "";
                    break;
                case "ProcesandoHtasRecibidas":
                    $whereStatusArticulo="and enrecibo >= 1";
                    // $whereStatus = "";
                    break;
                case "ProcesandoHerramientasFinalizadas":
                    $whereStatusArticulo="and enentrega>=1";
                    $whereStatus = "(p.status = 9) and";
                    break;
                case "ProcesandoHerramientasCanceladas":
                    $whereStatusArticulo="and encancelado>=1";
                    $whereStatus = "(p.status = 10) and";
                    break;
                case "ProcesandoHerramientas":
                    $whereStatusArticulo = "and pendientes>=1";
                    // $whereStatus = "";
                    break;
            }
        }
        $especial1 = "union select idPedido, status from articulos_especiales_pedidos where status in(2,3,4)";
        $especial2 = "union select idPedido, status, CVE_COMPRA from articulos_especiales_pedidos where status in(5, 6) and CVE_COMPRA is not NULL";
        $especial3 = "union select idPedido, status, CVE_COMPRA from articulos_especiales_pedidos where status in(7) and CVE_COMPRA is not NULL";
        $especial4 = "union select idPedido, status, CVE_COMPRA from articulos_especiales_pedidos where status in(9)";
        if (isset($_DATA['tipo'])|| isset($_GET['tipo'])) {
            $status = isset($_DATA['tipo']) ? $_DATA['tipo'] : $_GET['tipo'];
            switch ($status) {
                case "Material para Obra":
                    $whereTipo = " tipoPedido in (1)";
                    break;
                case "Herramienta Mayor":
                    $whereTipo = " tipoPedido in (2)";
                    break;
                case "Herramienta Personal":
                    $whereTipo = " tipoPedido in (3)";
                    break;
                case "Finalizados":
                    $whereTipo = "tipoPedido in (4)";
                    break;
                case "Herramientas":
                    $whereTipo = " tipoPedido in (2,3,6)";
                    $especial1 = "";
                    $especial2 = "";
                    $especial3 = "";
                    $especial4 = "";
            }
        }
        if (isset($_DATA['orden'])) {
            switch ($_DATA['orden']) {
                case "Mas antiguos primero":
                    $whereOrden = "ORDER BY p.fechaRequerida asc";
                    break;
                case "Mas recientes primero":
                    $whereOrden = "ORDER BY p.fechaRequerida desc";
                    break;
            }
        }
        if (isset($_DATA['tiempo'])) {
            switch ($_DATA['tiempo']) {
                case "A tiempo":
                    $whereTiempo = "date(p.fechaRequerida) > '$fechaActual' and";
                    break;
                case "Hoy":
                    $whereTiempo = "date(p.fechaRequerida) = '$fechaActual' and";
                    break;
                case "Atrasado":
                    $whereTiempo = "date(p.fechaRequerida) < '$fechaActual' and";
                    break;
            }
        }
  
        $pedidos = $pdo->query("SELECT 
        p.idPedido,
        cargo,
        c.nombre,
        p.descripcion,
        indicacionAdicional,
        idSolicitante,
        concat(dpr.apellidoPat, ' ', dpr.apellidoMat, ' ', dpr.nombre) as nombreSolicitante,
        idSupervisor,
        concat(dps.apellidoPat, ' ', dps.apellidoMat, ' ', dps.nombre) as nombreSupervisor,
        DATE(p.fechaRequerida) AS fechaRequerida,
        TIME(p.fechaRequerida) AS horaRequerida,
        fechaCierre,
        p.isDepurado,
        date(fechaSolicitud) as fechaSolicitud,
        $fecha
        p.status,
        tipoPedido,
        ruta,
        ifnull(pendientes.pendientes, 0) as artPendientes,
        ifnull(compras.encompra, 0) as artEnCompra,
        ifnull(recibidos.enrecibo, 0) as artEnRecibo,
        ifnull(entregados.enentrega, 0) as artEnEntrega,
        ifnull(cancelados.encancelado, 0) as artCancelados,
        date(p.fechaRequerida) as fechaRequerida,
        date(p.fechaDevoluciones) as fechaDevoluciones,
        ifnull(c.NOMBRE, 'Ninguno') as nombre_vend,
        csp.descripcion as desc_status,
        (select date(horaEntrega) from pedidosalmacen.entregas_compras ec 
            where ec.idPedido = p.idPedido
            order by horaEntrega desc
            limit 1) as fechaEntrega,
        (select count(*)  from (select   
                (SELECT 
                        COUNT(cve_art)
                    FROM
                        pedidosalmacen.articulos_pedidos
                    WHERE
                        FOLIO = f.folio) 
                FROM pedidosalmacen.folios f
            WHERE idPedido =p.idPedido and f.surtidor is null and f.recibe is null) p) as folios_sin_entregar
        FROM
        pedidosalmacen.pedidos p
        inner join pedidosalmacen.cat_status_pedido csp on csp.idStatus = p.status 
        left join sae.clie01 c on c.CLAVE = cargo
        left join sistemanomina_prueba.datospersonalesempleado dpr on dpr.idEmpleado = idSolicitante
        left join sistemanomina_prueba.datospersonalesempleado dps on dps.idEmpleado = idSupervisor
        left join (SELECT idPedido, count(idPedido) as pendientes  FROM pedidosalmacen.articulos_pedidos where status in(1) GROUP BY idPedido) as pendientes on pendientes.idPedido = p.idPedido
        left join (select count(idPedido) as encompra, idPedido from (select idPedido, status from articulos_pedidos where status in(2,3,4) $especial1) p group by idPedido) compras on compras.idPedido = p.idPedido
        left join (select count(idPedido) as enrecibo, idPedido from (select idPedido, status, CVE_COMPRA from articulos_pedidos where status in(5,6) and CVE_COMPRA is not NULL $especial2 ) p group by idPedido) recibidos on recibidos.idPedido = p.idPedido
        left join (select count(idPedido) as enentrega, idPedido from (select idPedido, status, CVE_COMPRA from articulos_pedidos where status in(7) and CVE_COMPRA is not NULL $especial3) p group by idPedido) entregados on entregados.idPedido = p.idPedido
        left join (select count(idPedido) as encancelado, idPedido from (select idPedido, status, CVE_COMPRA from articulos_pedidos where status in(9) $especial4) p group by idPedido) cancelados on cancelados.idPedido = p.idPedido
        WHERE p.sucursal = $sucursal and $whereStatus $whereTipo $whereStatusArticulo
        $sql_min_max")->fetchAll(PDO::FETCH_ASSOC);

        // * Conseguir el nombre del comprador de los articulos ajustados a su submodulos
        if($status=="ProcesandoCompras"){
            foreach ($pedidos as $index => $pedido) {
                $idPedido = $pedido["idPedido"];
                $temp = $pdo->query("SELECT 
                    concat(dpe.nombre, ' ', dpe.apellidoPat, ' ', dpe.apellidoMat) as nombreComprador
                    FROM
                    pedidosalmacen.pedidos p
                    inner join articulos_pedidos ap on ap.idPedido = p.idPedido
                    inner join compras c on c.idCompra = ap.CVE_COMPRA
                    inner join sistemanomina_prueba.datospersonalesempleado dpe on dpe.idEmpleado = c.comprador
                    left join (select count(idPedido) as encompra, idPedido from (select idPedido, status from articulos_pedidos where status in(2,3,4) union select idPedido, status from articulos_especiales_pedidos where status in(2,3,4)) p group by idPedido) compras on compras.idPedido = p.idPedido
                    where p.idPedido = $idPedido
                    group by nombreComprador  and encompra>=1
                union SELECT 
                    concat(dpe.nombre, ' ', dpe.apellidoPat, ' ', dpe.apellidoMat) as nombreComprador
                    FROM
                    pedidosalmacen.pedidos p
                    inner join articulos_especiales_pedidos ap on ap.idPedido = p.idPedido
                    inner join compras c on c.idCompra = ap.CVE_COMPRA
                    inner join sistemanomina_prueba.datospersonalesempleado dpe on dpe.idEmpleado = c.comprador
                    left join (select count(idPedido) as encompra, idPedido from (select idPedido, status from articulos_pedidos where status in(2,3,4) union select idPedido, status from articulos_especiales_pedidos where status in(2,3,4)) p group by idPedido) compras on compras.idPedido = p.idPedido
                    where p.idPedido = $idPedido
                    group by nombreComprador  and encompra>=1")->fetchAll(PDO::FETCH_COLUMN);
                // $lista_completa = array_merge($temp, $temp_dos);
                // $pedidos[$index]["compradores"] = join("   ", $temp) . "   " . join("   ", $temp_dos);
                $lista_completa = array_unique($temp);
                $pedidos[$index]["compradores"] = join(", ", $lista_completa);
            }
        }

        echo json_encode(["pedidos" => $pedidos]);
        exit;
    }
    //! Esto no modifica, pero fue necesario xq en el get no le puedo mandar los parametros.
    if (isset($_GET['herramienta'])) {//Corregir Multisucursal
        $sucursal =  isset($_GET['sucursal']) ?  $_GET['sucursal'] :  2;
        $fechaActual = date("Y-m-d");
        $_DATA = json_decode(file_get_contents('php://input'), true);
        $whereStatus = "p.status in (2,4,5,6,7,8) and";
        $whereTipo = "1,2,3,4";
        $whereOrden = "";
        $whereTiempo = "";
        if (isset($_DATA['status']) || isset($_GET['status'])) {
            $status = isset($_DATA['status']) ? $_DATA['status'] : $_GET['status'];
            switch ($status) {
                case "Finalizados":
                    $whereStatus = "p.status = 9 or p.status = 10 or p.status = 3 and";
                    break;
                case "Procesando":
                    $whereStatus = "p.status != 9 and p.status != 3 and p.status != 10 and p.status != 1 and";
                    break;
                case "ProcesandoDespacho":
                    $whereStatus = "p.status in (1,2,4) and";
                    break;
                case "ProcesandoCompras":
                    $whereStatus = "p.status = 5 and";
                    break;
                case "ProcesandoComprasRecibidas":
                    $whereStatus = "p.status = 9 and";
                    break;
                
            }
        }
        if (isset($_DATA['tipo'])) {
            switch ($_DATA['tipo']) {
                case "Material para Obra":
                    $whereTipo = "1";
                    break;
                case "Herramienta Mayor":
                    $whereTipo = "2";
                    break;
                case "Herramienta Personal":
                    $whereTipo = "3";
                    break;
                case "Finalizados":
                    $whereTipo = "4";
                    break;
            }
        }
        if (isset($_DATA['orden'])) {
            switch ($_DATA['orden']) {
                case "Mas antiguos primero":
                    $whereOrden = "ORDER BY fechaRequerida asc";
                    break;
                case "Mas recientes primero":
                    $whereOrden = "ORDER BY fechaRequerida desc";
                    break;
            }
        }
        if (isset($_DATA['tiempo'])) {
            switch ($_DATA['tiempo']) {
                case "A tiempo":
                    $whereTiempo = "date(fechaRequerida) > '$fechaActual' and";
                    break;
                case "Hoy":
                    $whereTiempo = "date(fechaRequerida) = '$fechaActual' and";
                    break;
                case "Atrasado":
                    $whereTiempo = "date(fechaRequerida) < '$fechaActual' and";
                    break;
            }
        }
        $pedidos = $pdo->query("SELECT 
            idPedido,
            cargo,
            c.nombre,
            descripcion,
            indicacionAdicional,
            idSolicitante,
            concat(dpr.apellidoPat, ' ', dpr.apellidoMat, ' ', dpr.nombre) as nombreSolicitante,
            idSupervisor,
            concat(dps.apellidoPat, ' ', dps.apellidoMat, ' ', dps.nombre) as nombreSupervisor,
            DATE(fechaRequerida) AS fechaRequerida,
            TIME(fechaRequerida) AS horaRequerida,
            fechaDevoluciones,
            fechaSolicitud,
            p.status,
            tipoPedido,
            ruta
            FROM
            pedidos p
            left join sae.clie01 c on c.CLAVE = cargo
            inner join sistemanomina_prueba.datospersonalesempleado dpr on dpr.idEmpleado = idSolicitante
            inner join sistemanomina_prueba.datospersonalesempleado dps on dps.idEmpleado = idSupervisor
            WHERE
            $whereStatus $whereTiempo tipoPedido in ($whereTipo) AND p.sucursal = $sucursal $whereOrden;")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["pedidos" => $pedidos]);
       
        exit;
    }

    if (isset($_GET['enterado'])) {
        $idPedido = $_GET['idPedido'];
        $pdo->exec("UPDATE pedidos set status = 4 where idPedido = $idPedido");
        exit;
    }

    if (isset($_GET['cancelar'])) {
        // Esto se encarga de cancelar un pedido.
        try {
            $pdo->beginTransaction();
            $idPedido = $_GET['idPedido'];
            $idUsuario = $_GET['idUsuario'];
            $fechaCancela = date("Y-m-d H:i:s");
            $articulos = $pdo->query("SELECT STATUS FROM articulos_pedidos where idPedido = $idPedido")->fetchAll(PDO::FETCH_COLUMN);
            $especiales = $pdo->query("SELECT STATUS from articulos_especiales_pedidos where idPedido = $idPedido")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($articulos as $status) {
                if ($status != 1 && $status != 2 && $status != 9) {
                    throw new Exception("Alguno de los articulos ya se esta procesando, no se puede cancelar el pedido");
                }
            }
            if($articulos){
                $pdo->exec("UPDATE articulos_pedidos set STATUS = 9, fechaCancela = '$fechaCancela', idUsuarioCancela = $idUsuario where idPedido = $idPedido");
            }
            foreach ($especiales as $status) {
                if ($status != 1 && $status != 2 && $status != 9) {
                    throw new Exception("Alguno de los articulos ya se esta procesando, no se puede cancelar el pedido");
                }
            }
            if($especiales){
                $pdo->exec("UPDATE articulos_especiales_pedidos set STATUS = 9, fechaCancela = '$fechaCancela', idUsuarioCancela = $idUsuario where idPedido = $idPedido");
            }
            $statusPedido = $pdo->query("SELECT status from pedidos where idPedido = $idPedido")->fetch(PDO::FETCH_COLUMN);
            if ($statusPedido != 1 && $statusPedido != 2) {
                throw new Exception("El pedido esta siendo procesado por almacen, no se puede cancelar");
            }
            // $fecha = date('Y-m-d');
            $pdo->exec("UPDATE pedidos set STATUS = 10, fechaCierre = '$fechaCancela' where idPedido = $idPedido");
            $pdo->commit();
            echo json_encode(["msg" => "El pedido se cancelo correctamente"]);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(["error" => true, "msg" => $e->getMessage(), "linea" => $e->getLine()]);
            exit;
        }
    }
}

function correo_hta_supervisor(
    $nombre_empleado, 
    $correo, 
    $articulos,
    $descripciones
){
    $html_articulos = "";
    foreach($articulos as $index =>$articulo){
        $clave = $articulos[$index];
        $desc = $descripciones[$index];
        $html_articulos=$html_articulos."<tr><td>$clave</td><td>$desc</td></tr>";
    }

    $message = "<p>$nombre_empleado</p> <br>
        <h1>Lista de Articulos Vencidos</h1> <hr> 
        <table border='1'>
            <thead>
                <tr>
                    <th>Clave</th>
                    <th>Descripción</th>
                </tr>
            </thead>
            <tbody>
                $html_articulos
            </tbody>
        <table>";

    $mail = new KMail();
    $mail->host("securemail25.carrierzone.com");
    $mail->port(1025);
    $mail->user("notificaciones@cebsa.mx");
    $mail->password("*WydDDjZPGT$11");
    $mail->from("notificaciones@cebsa.mx");
    $mail->reply("notificaciones@cebsa.mx");
    $mail->sender_name("Notificaciones");
    $mail->to($correo);
    // $mail->to("desarrollo@cebsa.mx"); //pruebas
    $mail->bcc("sistemas@cebsa.mx, desarrollo@cebsa.mx");
    $mail->subject("Usted tiene prestamos de herramientas vencidos");
    $mail->message($message);
    if (!$mail->send()) {
        $mail->debug();
        throw new Exception($mail->report());
    }
}

function correo_traspaso($pdo, $correo, $id, $status){
    $info_mtto = $pdo->query("SELECT mhm.clave, 
        (select i.DESCR FROM sae.inve01 i where i.CVE_ART = mhm.clave) as DESCR,
        (select concat(dpe.nombre, ' ', dpe.apellidoPat, ' ', dpe.apellidoMat)  
        FROM sistemanomina_prueba.datospersonalesempleado dpe where dpe.idEmpleado = mhm.supervisor) as supervisor_nombre
        FROM pedidosalmacen.mantenimiento_hta_mayor mhm
    where id = $id;")->fetch(PDO::FETCH_ASSOC);

    $supervisor = $info_mtto["supervisor_nombre"];
    $clave = $info_mtto["clave"];
    $descr = $info_mtto["DESCR"];

    if($status = 2){
        $subject = "A una herramienta se le ha dado mantenimiento";
        $mensaje = "Al articulo $clave - $descr se le ha dado un mantenimiento exitoso. Favor de contactar al supervisor $supervisor para hacer los cargos correspondientes";
    }else{
        $subject = "A una herramienta no se le puede dar mantenimiento";
        $mensaje = "Al articulo $clave - $descr no tiene reparación. Favor de contactar al supervisor $supervisor para hacer los cargos correspondientes";
    }

    $mail = new KMail();
    $mail->host("securemail25.carrierzone.com");
    $mail->port(1025);
    $mail->user("notificaciones@cebsa.mx");
    $mail->password("*WydDDjZPGT$11");
    $mail->from("notificaciones@cebsa.mx");
    $mail->reply("notificaciones@cebsa.mx");
    $mail->sender_name("Notificaciones");
    $mail->to($correo); //correo a enviar
    // $mail->to("desarrollo02@cebsa.mx");
    $mail->bcc("soporte@cebsa.mx, desarrollo@cebsa.mx");
    $mail->subject($subject);
    $mail->message($mensaje);
    // if (!$mail->send()) {
    //     $mail->debug();
    //     throw new Exception($mail->report());
    // }
}
