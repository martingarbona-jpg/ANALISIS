// Crea una copia aislada, inicia su propio Apache en loopback y lo detiene al finalizar.
// No modifica registros/configuración del proyecto ni conecta a servidores SMTP.
const fs=require('fs'), os=require('os'), path=require('path'), cp=require('child_process');
const root=path.resolve(__dirname,'..');
const apache='C:/laragon/bin/apache/httpd-2.4.54-win64-VS16';
const php='C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64';
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'analisis-auditoria-'));
const t=tmp.replaceAll('\\','/');
fs.mkdirSync(path.join(tmp,'app')); fs.mkdirSync(path.join(tmp,'sessions'));
for(const name of ['index.php','.htaccess']) fs.copyFileSync(path.join(root,name),path.join(tmp,'app',name));
fs.cpSync(path.join(root,'phpmailer'),path.join(tmp,'app','phpmailer'),{recursive:true});
fs.writeFileSync(path.join(tmp,'app','smtp_config.php'),"<?php define('SMTP_PASS', 'CLAVE_FICTICIA_SOLO_PRUEBAS');\n");
const disabled='mail,fsockopen,pfsockopen,stream_socket_client,popen,exec,shell_exec,system,passthru,proc_open';
let conf=`ServerRoot "${apache}"\nListen 127.0.0.1:18763\nServerName 127.0.0.1\nPidFile "${t}/httpd.pid"\nErrorLog "${t}/apache-error.log"\n`;
for(const m of ['authz_core','authz_host','authz_user','authn_core','auth_basic','dir','mime','rewrite','log_config']) conf+=`LoadModule ${m}_module modules/mod_${m}.so\n`;
conf+=`LoadModule php_module "${php}/php8apache2_4.dll"\nPHPIniDir "${php}"\nDocumentRoot "${t}"\nTypesConfig conf/mime.types\nDirectoryIndex index.php\n<Directory "${t}">\nAllowOverride All\nRequire all granted\nOptions FollowSymLinks\nphp_value session.save_path "${t}/sessions"\nphp_admin_value disable_functions "${disabled}"\n</Directory>\n<FilesMatch "\\.php$">\nSetHandler application/x-httpd-php\n</FilesMatch>\n`;
const config=path.join(tmp,'httpd.conf');fs.writeFileSync(config,conf);
const exe=path.join(apache,'bin','httpd.exe');
const run=cp.spawnSync(exe,['-t','-f',config],{encoding:'utf8',windowsHide:true});
if(run.status!==0) throw Error(run.stderr);
const server=cp.spawn(exe,['-X','-f',config],{windowsHide:true,stdio:'ignore'});
function execute(executable,args,output){
  const result=cp.spawnSync(executable,args,{cwd:root,encoding:'utf8',windowsHide:true,env:{...process.env,ANALISIS_TEST_DIR:tmp}});
  if(result.status!==0) throw Error(result.stderr || result.stdout);
  fs.writeFileSync(path.join(__dirname,output),result.stdout); console.log(result.stdout);
}
(async()=>{
  try {
    for(let i=0;i<30;i++){
      if(server.exitCode!==null) throw Error('Apache terminó: '+fs.readFileSync(path.join(tmp,'apache-error.log'),'utf8'));
      try { const response=await fetch('http://127.0.0.1:18763/app/index.php');if(response.status===200)break; } catch {}
      await new Promise(resolve=>setTimeout(resolve,100));
    }
    // Confirma que el puerto pertenece a la instancia que acabamos de iniciar.
    if(Number(fs.readFileSync(path.join(tmp,'httpd.pid'),'utf8').trim())!==server.pid) throw Error('La instancia de pruebas no es la esperada');
    execute(process.execPath,[path.join(__dirname,'pruebas.cjs')],'RESULTADOS_HTTP.json');
    execute(path.join(php,'php.exe'),['-d','disable_functions='+disabled,path.join(__dirname,'smtp_simulado.php'),tmp],'RESULTADOS_SMTP.txt');
    execute(path.join(php,'php.exe'),['-d','disable_functions='+disabled,path.join(__dirname,'phpmailer_mime.php'),tmp],'RESULTADOS_MIME.txt');
    console.log('Pruebas aisladas completadas. Copia temporal: '+tmp);
  } finally { server.kill(); }
})().catch(error=>{console.error(error);process.exitCode=1});
