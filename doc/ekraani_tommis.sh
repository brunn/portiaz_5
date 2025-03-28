#!/bin/bash
TEMP_FILE="/tmp/screenshot_temp.png"
USER="kasutaja"
SERVER="123.456.789.123"
TEMP_FILE="$HOME/Pildid/ajutine_screenshot.png"
gnome-screenshot -a -f "$TEMP_FILE" &
wait $!
if [ -f "$TEMP_FILE" ]; then
    filename=$(zenity --entry \
                      --title="Salvesta ekraanipilt" \
                      --text="Sisesta faili nimi (ilma laiendita):" \
                      --entry-text="ekraanipilt" \
                      --width=300)
    if [ -z "$filename" ]; then
        zenity --error --text="Faili nime ei sisestatud. Üleslaadimine tühistati."
        rm "$TEMP_FILE"
        exit 1
    fi
    FINAL_FILE="$HOME/Pildid/$filename.png"
    mkdir -p "$HOME/Pildid"
    mv "$TEMP_FILE" "$FINAL_FILE"
    scp "$FINAL_FILE" $USER@$SERVER:/var/www/html/marcmic_2/portiaz_5/F10/uploads/
    if [ $? -eq 0 ]; then
        curl -X POST "http://$SERVER/marcmic_2/portiaz_5/api.php" \
            -d "file_name=$filename.png" \
            -d "file_path=/F10/uploads/$filename.png" \
            -d "project=F10"
        zenity --info --text="Fail '$filename.png' on serverisse üles laaditud!" --width=300
    else
        zenity --error --text="Üleslaadimine ebaõnnestus. Kontrolli serveri seadeid."
    fi
else
    zenity --error --text="Ekraanipildi tegemine ebaõnnestus või tühistati."
fi

