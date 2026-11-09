<?php
global $wpdb; $p=$wpdb->prefix;
$out=['clientes'=>[],'invitados'=>[],'pedidos'=>[]];
$uids=$wpdb->get_col("SELECT DISTINCT u.ID FROM {$wpdb->users} u JOIN {$wpdb->usermeta} m ON m.user_id=u.ID AND m.meta_key='{$p}capabilities' WHERE m.meta_value LIKE '%customer%' OR m.meta_value LIKE '%subscriber%' OR u.ID IN (SELECT customer_id FROM {$p}wc_orders WHERE customer_id>0) ORDER BY u.ID");
$keys=$wpdb->get_col("SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE 'billing\_%' OR meta_key LIKE 'shipping\_%' ORDER BY meta_key");
foreach($uids as $id){ $u=get_userdata($id); if(!$u) continue;
 $r=['ID'=>$id,'usuario'=>$u->user_login,'email'=>$u->user_email,'nombre'=>$u->first_name,'apellidos'=>$u->last_name,'registro'=>$u->user_registered,'rol'=>implode(',',$u->roles)];
 foreach($keys as $k) $r[$k]=get_user_meta($id,$k,true);
 $o=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) n, SUM(CASE WHEN status IN ('wc-completed','wc-processing') THEN total_amount ELSE 0 END) t, MAX(date_created_gmt) l FROM {$p}wc_orders WHERE customer_id=%d AND type='shop_order'",$id));
 $r['pedidos']=(int)$o->n; $r['total_gastado']=(float)$o->t; $r['ultimo_pedido']=$o->l;
 $out['clientes'][]=$r; }
foreach($wpdb->get_results("SELECT * FROM {$p}wc_customer_lookup WHERE user_id IS NULL OR user_id=0",ARRAY_A) as $r) $out['invitados'][]=$r;
foreach($wpdb->get_results("SELECT o.id,o.date_created_gmt,o.status,o.total_amount,o.currency,o.customer_id,o.billing_email,o.payment_method_title,a.first_name,a.last_name,a.company,a.address_1,a.city,a.postcode,a.country,a.phone FROM {$p}wc_orders o LEFT JOIN {$p}wc_order_addresses a ON a.order_id=o.id AND a.address_type='billing' WHERE o.type='shop_order' ORDER BY o.id",ARRAY_A) as $r){
 $it=$wpdb->get_col($wpdb->prepare("SELECT CONCAT(order_item_name) FROM {$p}woocommerce_order_items WHERE order_id=%d AND order_item_type='line_item'",$r['id']));
 $r['productos']=implode(' | ',$it); $out['pedidos'][]=$r; }
if(!$out["pedidos"]){ echo "ordq=",$wpdb->last_error,"\n"; foreach(wc_get_orders(["limit"=>-1,"type"=>"shop_order"]) as $o){ $out["pedidos"][]=["id"=>$o->get_id(),"fecha"=>$o->get_date_created()?$o->get_date_created()->date("Y-m-d H:i"):"","estado"=>$o->get_status(),"total"=>$o->get_total(),"moneda"=>$o->get_currency(),"cliente_id"=>$o->get_customer_id(),"email"=>$o->get_billing_email(),"nombre"=>$o->get_billing_first_name(),"apellidos"=>$o->get_billing_last_name(),"empresa"=>$o->get_billing_company(),"direccion"=>$o->get_billing_address_1(),"ciudad"=>$o->get_billing_city(),"cp"=>$o->get_billing_postcode(),"pais"=>$o->get_billing_country(),"telefono"=>$o->get_billing_phone(),"pago"=>$o->get_payment_method_title(),"productos"=>implode(" | ",array_map(fn($i)=>$i->get_name()." x".$i->get_quantity(),$o->get_items()))]; } }
file_put_contents(getenv("EGOUT"),gzencode(json_encode($out),9)); echo "err=",$wpdb->last_error,"\n";
echo "n=",count($out['clientes'])," ",count($out['invitados'])," ",count($out['pedidos']),"\n";
