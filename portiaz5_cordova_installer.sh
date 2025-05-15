#!/bin/bash

# Skripti nimi: install_cordova_project.sh
# Eesmärk: Seadistab Cordova projekti, tõmbab logo ja ikooni, lisab platvormid, kontrollib telefoni ja kompileerib

# Muutujad
PROJECT_NAME="MRCMC"
BASE_DIR="$(pwd)"
PROJECT_DIR="$BASE_DIR/CORDOVA"
COUNTER=2
ANDROID_SDK_PATH="$HOME/Android/Sdk" # Kontrolli, kas see on sinu süsteemis õige
LOGO_URL="http://192.168.13.250/pildid/sinine_pall.png"

# Värvid teavituste jaoks
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Funktsioon veateadete jaoks
error_exit() {
    echo -e "${RED}Viga: $1${NC}" >&2
    exit 1
}

# 1. Kontrolli, kas Cordova ja ImageMagick on installitud
echo "Kontrollin Cordova installatsiooni..."
if ! command -v cordova &> /dev/null; then
    error_exit "Cordova pole installitud! Palun installi: npm install -g cordova"
fi
echo -e "${GREEN}Cordova on installitud: $(cordova -v)${NC}"

echo "Kontrollin ImageMagick installatsiooni..."
if ! command -v convert &> /dev/null; then
    echo -e "${YELLOW}ImageMagick pole installitud. Paigaldan...${NC}"
    sudo apt update && sudo apt install -y imagemagick || error_exit "ImageMagick paigaldamine ebaõnnestus"
fi
echo -e "${GREEN}ImageMagick on installitud${NC}"

# 2. Leia vaba kaustanimi (CORDOVA või CORDOVA2)
while [ -d "$PROJECT_DIR" ]; do
    PROJECT_DIR="$BASE_DIR/CORDOVA$COUNTER"
    ((COUNTER++))
done
echo "Loon projekti kausta: $PROJECT_DIR"
mkdir -p "$PROJECT_DIR" || error_exit "Kausta $PROJECT_DIR loomine ebaõnnestus"
cd "$PROJECT_DIR" || error_exit "Kausta $PROJECT_DIR sisenemine ebaõnnestus"

# 3. Seadista kataloogistruktuur ja kopeeri failid
echo "Loon www/, res/ ja res/icon/android/ kataloogid..."
mkdir -p www/css www/js www/img res/android res/icon/android || error_exit "Kataloogide loomine ebaõnnestus"

# Tõmba logo (kasutatakse rakenduse sees ja ikoonide loomiseks)
echo "Tõmban logo faili..."
if ! wget -O www/img/logo.png "$LOGO_URL"; then
    error_exit "Logo tõmbamine ebaõnnestus URL-ilt $LOGO_URL"
fi
echo -e "${GREEN}Logo tõmmatud: www/img/logo.png${NC}"

# Loo ikooni erinevad suurused
echo "Loon ikooni suurusi Androidile..."
for size in 36 48 72 96 144 192; do
    density=""
    case $size in
        36) density="ldpi" ;;
        48) density="mdpi" ;;
        72) density="hdpi" ;;
        96) density="xhdpi" ;;
        144) density="xxhdpi" ;;
        192) density="xxxhdpi" ;;
    esac
    convert www/img/logo.png -resize "${size}x${size}" res/icon/android/${density}.png || error_exit "Ikooni suuruse $size loomine ebaõnnestus"
done
echo -e "${GREEN}Ikoonid loodud: res/icon/android/${NC}"

# Kirjuta config.xml ikooniviidetega
echo "Kirjutan config.xml..."
cat > config.xml << 'EOF'
<?xml version='1.0' encoding='utf-8'?>
<widget id="io.cordova.hellocordova"
        version="1.0.0"
        xmlns="http://www.w3.org/ns/widgets"
        xmlns:cdv="http://cordova.apache.org/ns/1.0"
        xmlns:android="http://schemas.android.com/apk/res/android">
    <name>HelloCordova</name>
    <description>Sample Apache Cordova App</description>
    <author email="dev@cordova.apache.org" href="https://cordova.apache.org">
        Apache Cordova Team
    </author>
    <content src="index.html" />
    <access origin="*" />
    <allow-navigation href="*" />
    <allow-intent href="http://*/*" />
    <allow-intent href="https://*/*" />
    <preference name="MixedContentMode" value="always-allow" />
    <preference name="scheme" value="http" />
    <preference name="hostname" value="localhost" />
    <platform name="android">
        <icon src="res/icon/android/ldpi.png" density="ldpi" />
        <icon src="res/icon/android/mdpi.png" density="mdpi" />
        <icon src="res/icon/android/hdpi.png" density="hdpi" />
        <icon src="res/icon/android/xhdpi.png" density="xhdpi" />
        <icon src="res/icon/android/xxhdpi.png" density="xxhdpi" />
        <icon src="res/icon/android/xxxhdpi.png" density="xxxhdpi" />
        <edit-config file="app/src/main/AndroidManifest.xml" mode="merge" target="/manifest">
            <uses-permission android:name="android.permission.INTERNET" />
        </edit-config>
        <edit-config file="app/src/main/AndroidManifest.xml" mode="merge" target="/manifest/application">
            <application android:usesCleartextTraffic="true" />
        </edit-config>
        <resource-file src="res/android/network_security_config.xml" target="app/src/main/res/xml/network_security_config.xml" />
    </platform>
</widget>
EOF

# Kirjuta network_security_config.xml
echo "Kirjutan res/android/network_security_config.xml..."
cat > res/android/network_security_config.xml << 'EOF'
<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
    <domain-config cleartextTrafficPermitted="true">
        <domain includeSubdomains="true">192.168.13.253</domain>
    </domain-config>
    <domain-config cleartextTrafficPermitted="false">
        <domain includeSubdomains="true">192.168.13.250</domain>
        <domain>192.168.13.250</domain>
    </domain-config>
</network-security-config>
EOF

# Kirjuta index.html
echo "Kirjutan www/index.html..."
cat > www/index.html << 'EOF'
<!DOCTYPE html>
<html lang="et">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Postituste ajalugu</title>
<style>
body {
font-family: 'Arial', sans-serif;
margin: 0;
background-color: #f4f4f8;
color: #333;
line-height: 1.6;
}
.container {
width: 85%;
margin: auto;
padding: 20px;
}
#errorMessage {
color: #d32f2f;
background-color: #ffebee;
padding: 10px;
margin-bottom: 20px;
border-radius: 4px;
display: none;
}
.post {
margin-bottom: 20px;
padding: 15px;
background-color: white;
box-shadow: 0 2px 5px rgba(0,0,0,0.1);
border-radius: 6px;
}
.post-header {
background-color: #4CAF50;
color: white;
padding: 10px;
border-radius: 6px 6px 0 0;
display: flex;
justify-content: space-between;
align-items: center;
}
.post-title {
font-size: 1.2em;
margin: 0;
}
.post-time {
font-size: 0.8em;
opacity: 0.8;
}
.post-content {
padding: 10px;
}
</style>
</head>
<body>
<div class="container">
<h1>Postitused</h1>
<div id="errorMessage"></div>
<div id="postList">
</div>
</div>
<script>
function formatText(text) {
return text
.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
.replace(/\*(.*?)\*/g, '<em>$1</em>')
.replace(/\n/g, '<br>')
.trim();
}
async function laadipostitused() {
const url = 'http://192.168.13.253/marcmic_2/portiaz_5/api2.php';
const postList = document.getElementById('postList');
const errorDiv = document.getElementById('errorMessage');
try {
const response = await fetch(url);
if (!response.ok) throw new Error('HTTP viga: ' + response.status);
const json = await response.json();
if (json.status !== 'success') throw new Error('Vigane vastus serverist');
const postsHtml = json.data.map(post => {
const date = new Date(post.timestamp * 1000).toISOString().replace('T', ' ').slice(0, 19);
const cleanText = formatText(post.text);
return `
<div class="post">
<div class="post-header">
<h2 class="post-title">${post.tree_name}</h2>
<span class="post-time">${date}</span>
</div>
<div class="post-content">
${cleanText}
</div>
</div>
`;
}).join('');
postList.innerHTML = postsHtml || '<p>Postitusi pole</p>';
errorDiv.style.display = 'none';
} catch (err) {
errorDiv.textContent = 'Viga: ' + err.message;
errorDiv.style.display = 'block';
postList.innerHTML = '<p>Andmeid ei saadud</p>';
}
}
document.addEventListener('DOMContentLoaded', laadipostitused);
</script>
</body>
</html>
EOF

# Kirjuta index.js
echo "Kirjutan www/js/index.js..."
cat > www/js/index.js << 'EOF'
document.addEventListener('deviceready', onDeviceReady, false);
function onDeviceReady() {
    console.log('Running cordova-' + cordova.platformId + '@' + cordova.version);
    document.getElementById('deviceready').classList.add('ready');
}
EOF

# Kirjuta index.css
echo "Kirjutan www/css/index.css..."
cat > www/css/index.css << 'EOF'
* {
-webkit-tap-highlight-color: rgba(0,0,0,0);
}

body {
-webkit-touch-callout: none;
-webkit-text-size-adjust: none;
-webkit-user-select: none;
background-color:#E4E4E4;
background-image:linear-gradient(to bottom, #A7A7A7 0%, #E4E4E4 51%);
font-family: system-ui, -apple-system, -apple-system-font, 'Segoe UI', 'Roboto', sans-serif;
font-size:12px;
height:100vh;
margin:0px;
padding:0px;
padding: env(safe-area-inset-top, 0px) env(safe-area-inset-right, 0px) env(safe-area-inset-bottom, 0px) env(safe-area-inset-left, 0px);
text-transform:uppercase;
width:100%;
}
.app {
background:url(../img/logo.png) no-repeat center top;
position:absolute;
left:50%;
top:50%;
height:50px;
width:225px;
text-align:center;
padding:180px 0px 0px 0px;
margin:-115px 0px 0px -112px;
}
@media screen and (min-aspect-ratio: 1/1) and (min-width:400px) {
.app {
background-position:left center;
padding:75px 0px 75px 170px;
margin:-90px 0px 0px -198px;
}
}
h1 {
font-size:24px;
font-weight:normal;
margin:0px;
overflow:visible;
padding:0px;
text-align:center;
}
.event {
border-radius:4px;
color:#FFFFFF;
font-size:12px;
margin:0px 30px;
padding:2px 0px;
}
.event.listening {
background-color:#333333;
display:block;
}
.event.received {
background-color:#4B946A;
display:none;
}
#deviceready.ready .event.listening { display: none; }
#deviceready.ready .event.received { display: block; }
@keyframes fade {
from { opacity: 1.0; }
50% { opacity: 0.4; }
to { opacity: 1.0; }
}
.blink {
animation:fade 3000ms infinite;
-webkit-animation:fade 3000ms infinite;
}
@media screen and (prefers-color-scheme: dark) {
body {
background-image:linear-gradient(to bottom, #585858 0%, #1B1B1B 51%);
}
}
EOF

# 4. Seadista Cordova projekt
echo "Seadistan Cordova projekti..."
cordova create temp io.cordova.hellocordova HelloCordova || error_exit "Cordova projekti loomine ebaõnnestus"
# Kopeeri meie failid üle vaikimisi loodud failide
mv temp/.cordova .cordova
rm -rf temp
echo -e "${GREEN}Cordova projekt seadistatud${NC}"

# 5. Lisa platvormid
echo "Lisan platvormid: android, browser..."
cordova platform add android || error_exit "Androidi platvormi lisamine ebaõnnestus"
cordova platform add browser || error_exit "Brauseri platvormi lisamine ebaõnnestus"
echo -e "${GREEN}Platvormid lisatud${NC}"

# 6. Kontrolli telefoni ühendust
echo "Kontrollin Android-seadme ühendust..."
adb kill-server
adb start-server
if adb devices | grep -q device; then
    echo -e "${GREEN}Android-seade on ühendatud!${NC}"
    DEVICE_CONNECTED=true
else
    echo -e "${YELLOW}Android-seadet ei leitud, jätkan APK ehitamisega...${NC}"
    DEVICE_CONNECTED=false
fi

# 7. Puhasta ja kompileeri
echo "Puhastan projekti..."
cordova clean || error_exit "Projekti puhastamine ebaõnnestus"
echo "Valmistan ette projekti..."
cordova prepare || error_exit "Projekti ettevalmistamine ebaõnnestus"
echo "Kompileerin Androidi jaoks..."
if [ "$DEVICE_CONNECTED" = true ]; then
    cordova run android || error_exit "Androidi kompileerimine ebaõnnestus"
else
    cordova build android || error_exit "Androidi ehitamine ebaõnnestus"
fi
echo "Kompileerin brauseri jaoks..."
cordova run browser || error_exit "Brauseri kompileerimine ebaõnnestus"

# 8. Teavita lõpptulemus
APK_PATH="$PROJECT_DIR/platforms/android/app/build/outputs/apk/debug/app-debug.apk"
if [ -f "$APK_PATH" ]; then
    echo -e "${GREEN}Protsess lõpetatud! APK asub: $APK_PATH${NC}"
else
    echo -e "${YELLOW}APK-d ei leitud, kontrolli ehitamise logisid${NC}"
fi