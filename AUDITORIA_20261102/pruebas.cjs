// Ejecutar con Node contra la copia aislada indicada por ANALISIS_TEST_DIR.
// El Apache de pruebas debe bloquear funciones de red/correo; nunca usar producción.
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert/strict');
const tmp = process.env.ANALISIS_TEST_DIR;
if (!tmp || !path.basename(tmp).startsWith('analisis-auditoria-')) throw Error('Se requiere carpeta temporal aislada');
const base = 'http://127.0.0.1:18763/app/';
const app = path.join(tmp, 'app');
let cookie = '', token = '';
const checks = [];
function check(name, condition) { assert(condition, name); checks.push(name); }
async function req(url = 'index.php', options = {}) {
  const res = await fetch(base + url, { ...options, redirect: 'manual', headers: { Cookie: cookie, ...options.headers } });
  const set = res.headers.get('set-cookie');
  if (set) cookie = set.split(';')[0];
  return res;
}
async function post(fields, files = [], csrf = true) {
  const form = new FormData();
  for (const [k,v] of Object.entries(fields)) form.set(k,v);
  if (csrf) form.set('csrf_token', token);
  for (const [k,name,body,type] of files) form.append(k,new Blob([body],{type}),name);
  const res = await req('index.php',{method:'POST',body:form});
  return { res, data: await res.json() };
}
async function records() { return (await (await req('index.php?api=listar&estado=Todos')).json()).registros; }
(async()=>{
  let res = await req(); let html = await res.text();
  token = html.match(/name="csrf_token" value="([a-f0-9]+)"/)[1];
  check('Login público disponible; cookie HttpOnly y SameSite=Lax', html.includes('login_password') && /httponly/i.test(res.headers.get('set-cookie')) && /samesite=lax/i.test(res.headers.get('set-cookie')));
  check('API sin sesión no expone registros', (await (await req('index.php?api=listar')).text()).includes('login_password'));
  check('Login rechaza CSRF faltante', (await post({login_password:'incorrecta'},[],false)).res.status === 403);
  const password = fs.readFileSync(path.join(process.cwd(),'BACKUP_ANTES_AUDITORIA_20261102','index.php'),'utf8').match(/define\('PASSWORD_SISTEMA', '([^']+)'\)/)[1];
  const oldCookie = cookie;
  const login = new FormData();login.set('login_password',password);login.set('csrf_token',token);
  res = await req('index.php',{method:'POST',body:login});
  check('Contraseña existente funciona y se regenera ID de sesión', res.status === 302 && oldCookie !== cookie);
  html = await (await req()).text();
  token = html.match(/formData\.set\('csrf_token', "([a-f0-9]+)"\)/)[1];
  const original = fs.readFileSync('BACKUP_ANTES_AUDITORIA_20261102/index.php','utf8');
  const styles = s => [...s.matchAll(/<style>([\s\S]*?)<\/style>/g)].map(m=>m[1].replace(/\r\n/g,'\n'));
  check('Todos los estilos permanecen idénticos', JSON.stringify(styles(original)) === JSON.stringify(styles(fs.readFileSync('index.php','utf8'))));
  for(const m of html.matchAll(/<script>([\s\S]*?)<\/script>/g)) new vm.Script(m[1]);
  check('JavaScript generado sin errores de sintaxis', true);
  const script=html.match(/<script>([\s\S]*?)<\/script>/)[1];
  const helpers=script.slice(script.indexOf('function htmlAttr('),script.indexOf('function adjuntosLista('));
  const context=vm.createContext({marcarRealizado:(...args)=>context.args=args});
  vm.runInContext(helpers,context);
  const payload="');globalThis.ataque=1;//";
  const decode=s=>s.replace(/&quot;/g,'"').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&');
  for(const valor of [payload,'comillas " y & entidad &#39;',"una ' comilla",'línea\nnueva']) {
    const seguro=context.jsString(valor);
    check('Escape HTML de atributo para dato '+JSON.stringify(valor),!/["<>]/.test(seguro));
    vm.runInContext(decode("marcarRealizado('id', '"+seguro+"', 0, false, '')"),context);
    check('Dato JavaScript preservado sin ejecución '+JSON.stringify(valor),context.args[1]===valor && context.ataque===undefined);
  }
  let out = await post({accion:'crear',nombre:'Ficticio',dni:'0000'},[],false);
  check('Operaciones autenticadas rechazan CSRF faltante', out.res.status===403 && !(await records()).length);
  out = await post({accion:'crear',nombre:'',dni:'0000'});
  check('Formulario exige nombre', out.data.ok === false);
  out = await post({accion:'crear',nombre:'Ficticio',dni:'0000',email:'invalido'});
  check('Formulario rechaza email inválido', out.data.ok === false);
  const pdf = Buffer.from('%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n');
  for(const [name,body] of [['shell.php',pdf],['falso.pdf',Buffer.from('<?php echo 123; ?>')]]) {
    out = await post({accion:'crear',nombre:'Ficticio',dni:'0000'},[['archivo[]',name,body,'application/pdf']]);
    check('Rechaza documento '+name,out.data.ok===false);
  }
  out = await post({accion:'crear',nombre:'Paciente ficticio',dni:'0000',pagado:'No',estado:'Pendiente'},[['archivo[]','pedido & # prueba.pdf',pdf,'application/pdf']]);
  check('Carga legítima de pedido PDF',out.data.ok===true);
  let r=(await records())[0]; const id=r.id, oldPath=r.adjunto[0];
  res=await req(oldPath.split('/').map(encodeURIComponent).join('/')+'?archivo=index.php');
  check('URL existente con espacios, & y # entrega PDF y no acepta override de query',res.status===200 && res.headers.get('content-type').startsWith('application/pdf') && Buffer.from(await res.arrayBuffer()).equals(pdf));
  const nombreEspecial = path.dirname(oldPath) + "/histórico & # ' documento.pdf";
  fs.copyFileSync(path.join(app,oldPath),path.join(app,nombreEspecial));
  res=await req(nombreEspecial.split('/').map(encodeURIComponent).join('/'));
  check('Documento histórico con &, #, acento y comilla conserva acceso',res.status===200 && Buffer.from(await res.arrayBuffer()).equals(pdf));
  const inerte = '<?php echo "MARCADOR_PRUEBA"; ?>';
  fs.writeFileSync(path.join(app,'adjuntos','prueba.php.jpg'),inerte);
  res=await req('adjuntos/prueba.php.jpg');
  check('Documento de doble extensión se entrega como bytes, sin interpretar PHP',res.status===200 && (await res.text())===inerte && res.headers.get('content-type').startsWith('image/jpeg'));
  const savedCookie=cookie; cookie='';res=await req(oldPath.split('/').map(encodeURIComponent).join('/'));
  check('Documento sin sesión no se entrega',!(res.headers.get('content-type')||'').startsWith('application/pdf'));cookie=savedCookie;
  for(const url of ['registros.json','smtp_config.php','phpmailer/src/PHPMailer.php','.git/config','logs/mail_error.log','BACKUP_ANTES_AUDITORIA_20261102/index.php','AUDITORIA_20261102/pruebas.cjs']) {
    res=await req(url);check('Apache bloquea '+url,res.status===403);
  }
  for(const archivo of ['index.php','resultados/../index.php','adjuntos/../index.php','../index.php','php://filter','resultados/../../index.php']) {
    res=await req('index.php?archivo='+encodeURIComponent(archivo));check('Rechaza ruta '+archivo,res.status===404);
  }
  out=await post({accion:'editarRegistro',id,nombre:'Ficticio editado',email:'',pagado:'No',monto:'0'});
  check('Edición de registro',out.data.ok===true && (await records())[0].nombre==='Ficticio editado');
  out=await post({accion:'actualizarPago',id,pagado:'Sí',monto:'100'});check('Actualización de pago',out.data.ok===true);
  out=await post({accion:'realizar',id,monto:'100'});r=(await records())[0];
  check('Realizar conserva y mueve pedido',out.data.ok===true && r.estado==='Realizado' && r.adjunto[0].includes('/realizados/') && fs.existsSync(path.join(app,r.adjunto[0])));
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=','base64');
  out=await post({accion:'subirResultado',id},[['resultado','resultado.png',png,'image/png']]);r=(await records())[0];
  check('Resultado PNG; sin email no intenta enviar',out.data.ok===true && out.data.enviado===false && out.data.error==='Sin email cargado' && !!r.resultado);
  out=await post({accion:'subirResultado',id},[['resultado','resultado.pdf',pdf,'application/pdf']]);r=(await records())[0];
  check('Reemplazo de resultado y limpieza de anterior',out.data.ok===true && r.resultado.endsWith('.pdf') && !fs.existsSync(path.join(app,path.dirname(r.resultado),'resultado.png')));
  out=await post({accion:'reenviarEmail',id});check('Reenvío sin destinatario conserva estado sin enviar',out.data.ok===true && out.data.enviado===false);
  res=await req('index.php?export=informe_mensual&mes='+r.fechaISO.slice(0,7));
  check('Informe Excel mensual disponible',res.status===200 && /application\/vnd.ms-excel/.test(res.headers.get('content-type')) && (await res.text()).includes('Ficticio editado'));
  const resumen=await(await req('index.php?api=resumen')).json();check('Resumen conserva cantidades y monto',resumen.totalRealizados===1 && resumen.totalMonto===100);
  out=await post({accion:'eliminar',id});check('Elimina únicamente registro y documentos de prueba',out.data.ok===true && (await records()).length===0 && !fs.existsSync(path.join(app,r.resultado)));
  const second=await fetch(base+'index.php');
  let secondCookie=second.headers.get('set-cookie').split(';')[0];
  const secondToken=(await second.text()).match(/name="csrf_token" value="([a-f0-9]+)"/)[1];
  const login2=new FormData();login2.set('login_password',password);login2.set('csrf_token',secondToken);
  const secondLogin=await fetch(base+'index.php',{method:'POST',body:login2,redirect:'manual',headers:{Cookie:secondCookie}});
  secondCookie=secondLogin.headers.get('set-cookie').split(';')[0];
  const parallel=await Promise.all(Array.from({length:10},async(_,i)=>{
    const form=new FormData();for(const [k,v] of Object.entries({accion:'crear',nombre:'Concurrente ficticio '+i,dni:'0000',csrf_token:i%2?secondToken:token}))form.set(k,v);
    return (await fetch(base+'index.php',{method:'POST',body:form,headers:{Cookie:i%2?secondCookie:cookie}})).json();
  }));
  const concurrentRecords=await records();
  check('Diez altas simultáneas entre dos sesiones conservan todos los registros',parallel.every(x=>x.ok) && concurrentRecords.length===10);
  for(const r of concurrentRecords) await post({accion:'eliminar',id:r.id});
  fs.writeFileSync(path.join(app,'registros.json'),'{JSON incompleto');
  out=await post({accion:'crear',nombre:'Ficticio',dni:'0000'});
  check('JSON corrupto no se convierte en lista vacía ni se sobrescribe',out.data.ok===false && fs.readFileSync(path.join(app,'registros.json'),'utf8')==='{JSON incompleto');
  fs.writeFileSync(path.join(app,'registros.json'),'[]');
  await req('index.php?logout=1');check('Logout invalida acceso API',(await(await req('index.php?api=listar')).text()).includes('login_password'));
  console.log(JSON.stringify({passed:checks.length,checks},null,2));
})().catch(e=>{console.error(e);process.exitCode=1});
