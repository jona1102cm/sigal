@echo off
setlocal EnableExtensions DisableDelayedExpansion

rem Instalador de la autoridad certificadora interna de SIGAL Beta.
rem Distribuya este archivo junto a sigal-beta-ca.crt, sin cambiar sus nombres.

set "CERTIFICATE=%~dp0sigal-beta-ca.crt"
set "SIGAL_URL=https://172.16.11.225"
set "EXPECTED_CERTIFICATE_SHA256=11E42EC68636BA96B2C6ECDF1E3976DC1D65956011E149275E8C3AF3E3159352"
set "EXPECTED_THUMBPRINT=6B97A2785FE5494E4F82B624A6F460E13470288D"

rem La instalacion en el almacen de equipo requiere permisos de administrador.
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$principal = [Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent()); if ($principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { exit 0 }; exit 1" >nul 2>&1
if not errorlevel 1 goto :install

echo.
echo Se solicitara permiso de administrador para instalar el certificado de SIGAL.
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
if errorlevel 1 (
    echo.
    echo No se pudo solicitar el permiso de administrador.
    echo Ejecute este archivo con clic derecho ^> Ejecutar como administrador.
    pause
)
exit /b

:install
if not exist "%CERTIFICATE%" (
    echo.
    echo No se encontro el archivo sigal-beta-ca.crt junto a este instalador.
    echo Copie ambos archivos en la misma carpeta y vuelva a ejecutarlo.
    pause
    exit /b 1
)

rem Verifica que se use exactamente la autoridad certificadora oficial de SIGAL.
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$hash = (Get-FileHash -LiteralPath '%CERTIFICATE%' -Algorithm SHA256).Hash; if ($hash -ne '%EXPECTED_CERTIFICATE_SHA256%') { exit 1 }"
if errorlevel 1 (
    echo.
    echo El certificado no coincide con la autoridad certificadora oficial de SIGAL.
    echo Solicite a Sistemas una copia actualizada.
    pause
    exit /b 1
)

echo.
echo Instalando la autoridad certificadora de SIGAL...
certutil.exe -addstore -f Root "%CERTIFICATE%"
if errorlevel 1 (
    echo.
    echo No fue posible instalar el certificado.
    echo Verifique que este archivo se ejecute como administrador.
    pause
    exit /b 1
)

rem Confirma que la autoridad se registro en el almacen de confianza del equipo.
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$certificate = Get-ChildItem -Path Cert:\LocalMachine\Root | Where-Object { $_.Thumbprint -eq '%EXPECTED_THUMBPRINT%' }; if ($null -eq $certificate) { exit 1 }"
if errorlevel 1 (
    echo.
    echo El certificado no pudo ser confirmado en las raices de confianza del equipo.
    pause
    exit /b 1
)

echo.
echo Certificado instalado correctamente.
echo Cierre y vuelva a abrir Chrome o Edge si ya estaban abiertos.
echo Para Firefox, habilite "Importar raices empresariales" o importe este certificado en Autoridades.
echo.
start "SIGAL Beta" "%SIGAL_URL%"
pause
exit /b 0
