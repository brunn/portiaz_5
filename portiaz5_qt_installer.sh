#!/bin/bash

# Küsi kausta nimi
read -p "Sisesta projekti kausta nimi: " PROJECT_NAME

# Loo projekti kaust
mkdir -p "$PROJECT_NAME"
cd "$PROJECT_NAME"

# Loo .pro fail
cat <<EOF > "$PROJECT_NAME.pro"
QT += core gui network
greaterThan(QT_MAJOR_VERSION, 4): QT += widgets
TARGET = $PROJECT_NAME
TEMPLATE = app
SOURCES += main.cpp \\
           mainwindow.cpp
HEADERS += mainwindow.h
FORMS += mainwindow.ui
EOF

# Loo main.cpp fail
cat <<EOF > main.cpp
#include "mainwindow.h"
#include <QApplication>

int main(int argc, char *argv[]) {
    QApplication a(argc, argv);
    MainWindow w;
    w.show();
    return a.exec();
}
EOF

# Loo mainwindow.h fail
cat <<EOF > mainwindow.h
#ifndef MAINWINDOW_H
#define MAINWINDOW_H

#include <QMainWindow>
#include <QNetworkAccessManager>
#include <QNetworkReply>
#include <QJsonDocument>
#include <QJsonObject>
#include <QJsonArray>
#include <QVBoxLayout>
#include <QHBoxLayout>
#include <QLabel>
#include <QScrollArea>
#include <QWidget>
#include <QTimer>
#include <QPushButton>

QT_BEGIN_NAMESPACE
namespace Ui { class MainWindow; }
QT_END_NAMESPACE

class MainWindow : public QMainWindow {
    Q_OBJECT

public:
    MainWindow(QWidget *parent = nullptr);
    ~MainWindow() override;

private slots:
    void fetchData();
    void dataReadyRead(QNetworkReply *reply);
    void refreshData();

private:
    Ui::MainWindow *ui;
    QNetworkAccessManager *networkManager;
    QVBoxLayout *mainLayout;
    QWidget *scrollWidget;
    QScrollArea *scrollArea;
    QVBoxLayout *scrollLayout;
    QLabel *statusLabel;
    QPushButton *refreshButton;
    
    void clearScrollLayout();
    QString formatText(const QString &text);
    void showError(const QString &message);
};
#endif // MAINWINDOW_H
EOF

# Loo mainwindow.cpp fail
cat <<EOF > mainwindow.cpp
#include "mainwindow.h"
#include "ui_mainwindow.h"
#include <QDebug>
#include <QDateTime>
#include <QUrl>
#include <QNetworkRequest>
#include <QMessageBox>

MainWindow::MainWindow(QWidget *parent)
    : QMainWindow(parent)
    , ui(new Ui::MainWindow) {
    ui->setupUi(this);
    
    // Set window title
    setWindowTitle("Postitused");
    
    // Create central widget if not already set by ui
    QWidget *centralWidget = new QWidget(this);
    setCentralWidget(centralWidget);
    
    // Main layout
    mainLayout = new QVBoxLayout(centralWidget);
    
    // Header layout
    QHBoxLayout *headerLayout = new QHBoxLayout();
    
    // Add a header
    QLabel *headerLabel = new QLabel("<h1>Postitused</h1>");
    headerLayout->addWidget(headerLabel);
    
    // Add refresh button
    refreshButton = new QPushButton("Värskenda");
    connect(refreshButton, &QPushButton::clicked, this, &MainWindow::refreshData);
    headerLayout->addWidget(refreshButton, 0, Qt::AlignRight);
    
    mainLayout->addLayout(headerLayout);
    
    // Add status label
    statusLabel = new QLabel();
    statusLabel->setStyleSheet("padding: 10px; border-radius: 4px;");
    statusLabel->setWordWrap(true);
    statusLabel->hide();
    mainLayout->addWidget(statusLabel);
    
    // Scroll area
    scrollArea = new QScrollArea();
    scrollWidget = new QWidget();
    scrollLayout = new QVBoxLayout(scrollWidget);
    scrollLayout->setAlignment(Qt::AlignTop);
    scrollWidget->setLayout(scrollLayout);
    scrollArea->setWidgetResizable(true);
    scrollArea->setWidget(scrollWidget);
    
    mainLayout->addWidget(scrollArea);
    
    // Network manager
    networkManager = new QNetworkAccessManager(this);
    connect(networkManager, &QNetworkAccessManager::finished, this, &MainWindow::dataReadyRead);
    
    // Load data
    fetchData();
}

MainWindow::~MainWindow() {
    delete ui;
}

void MainWindow::refreshData() {
    refreshButton->setEnabled(false);
    fetchData();
    QTimer::singleShot(2000, this, [this]() {
        refreshButton->setEnabled(true);
    });
}

void MainWindow::fetchData() {
    QUrl url("http://192.168.13.253/marcmic_2/portiaz_5/api2.php");
    QNetworkRequest request(url);
    
    // Show loading message
    statusLabel->setText("Laadin postitusi...");
    statusLabel->setStyleSheet("background-color: #e3f2fd; color: #0d47a1; padding: 10px; border-radius: 4px;");
    statusLabel->show();
    
    clearScrollLayout();
    
    // Make the request
    networkManager->get(request);
}

void MainWindow::clearScrollLayout() {
    if (!scrollLayout) return;
    
    QLayoutItem *item;
    while ((item = scrollLayout->takeAt(0)) != nullptr) {
        if (item->widget()) {
            delete item->widget();
        }
        delete item;
    }
}

void MainWindow::dataReadyRead(QNetworkReply *reply) {
    if (!reply) return;
    
    // Clear previous content
    clearScrollLayout();
    
    if (reply->error() == QNetworkReply::NoError) {
        QByteArray data = reply->readAll();
        QJsonDocument jsonDoc = QJsonDocument::fromJson(data);
        
        if (jsonDoc.isObject()) {
            QJsonObject jsonObj = jsonDoc.object();
            
            if (jsonObj.contains("status") && jsonObj["status"].toString() == "success") {
                if (jsonObj.contains("data") && jsonObj["data"].isArray()) {
                    QJsonArray dataArray = jsonObj["data"].toArray();
                    
                    if (dataArray.isEmpty()) {
                        QLabel *noPostsLabel = new QLabel("Postitusi pole");
                        scrollLayout->addWidget(noPostsLabel);
                    } else {
                        for (const QJsonValue &val : dataArray) {
                            QJsonObject post = val.toObject();
                            QString treeName = post["tree_name"].toString();
                            qint64 timestamp = post["timestamp"].toInt();
                            QString text = post["text"].toString();
                            
                            // Create post container widget
                            QWidget *postWidget = new QWidget();
                            postWidget->setObjectName("postWidget");
                            postWidget->setStyleSheet(
                                "QWidget#postWidget {"
                                "  background-color: white;"
                                "  border-radius: 6px;"
                                "  margin-bottom: 20px;"
                                "}"
                            );
                            
                            QVBoxLayout *postLayout = new QVBoxLayout(postWidget);
                            postLayout->setContentsMargins(0, 0, 0, 0);
                            postLayout->setSpacing(0);
                            
                            // Format text
                            text = formatText(text);
                            
                            // Format date and time
                            QDateTime dateTime = QDateTime::fromSecsSinceEpoch(timestamp);
                            QString formattedDateTime = dateTime.toString("dd.MM.yyyy hh:mm:ss");
                            
                            // Create header widget
                            QWidget *headerWidget = new QWidget();
                            headerWidget->setStyleSheet(
                                "background-color: #4CAF50; color: white; padding: 10px; border-radius: 6px 6px 0 0;"
                            );
                            
                            QHBoxLayout *headerLayout = new QHBoxLayout(headerWidget);
                            
                            QLabel *titleLabel = new QLabel(QString("<h2 style='margin:0;'>%1</h2>").arg(treeName));
                            QLabel *timeLabel = new QLabel(QString("<span style='font-size:0.8em;'>%1</span>").arg(formattedDateTime));
                            
                            headerLayout->addWidget(titleLabel);
                            headerLayout->addWidget(timeLabel, 0, Qt::AlignRight);
                            
                            // Create content widget
                            QLabel *contentLabel = new QLabel(text);
                            contentLabel->setWordWrap(true);
                            contentLabel->setStyleSheet("padding: 10px;");
                            contentLabel->setTextFormat(Qt::RichText);
                            
                            // Add widgets to post layout
                            postLayout->addWidget(headerWidget);
                            postLayout->addWidget(contentLabel);
                            
                            // Add post to scroll layout
                            scrollLayout->addWidget(postWidget);
                        }
                    }
                    
                    // Hide status message and show success
                    statusLabel->setText("Postitused laaditud edukalt");
                    statusLabel->setStyleSheet("background-color: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 4px;");
                    
                    // Hide status after 3 seconds
                    QTimer::singleShot(3000, this, [this]() {
                        statusLabel->hide();
                    });
                }
            } else {
                showError("Vigane vastus serverist: " + 
                          (jsonObj.contains("message") ? jsonObj["message"].toString() : "Tundmatu viga"));
            }
        } else {
            showError("Vigane JSON vastus");
        }
    } else {
        showError("Võrgu viga: " + reply->errorString());
    }
    
    reply->deleteLater();
}

QString MainWindow::formatText(const QString &text) {
    QString formatted = text;
    
    // First, escape any HTML that might be in the text
    formatted.replace("<", "&lt;").replace(">", "&gt;");
    
    // Now apply our formatting
    // Handle double asterisks for bold (need to handle them first)
    int pos = 0;
    while ((pos = formatted.indexOf("**", pos)) != -1) {
        int end = formatted.indexOf("**", pos + 2);
        if (end != -1) {
            QString boldText = formatted.mid(pos + 2, end - pos - 2);
            formatted.replace(pos, end - pos + 2, "<strong>" + boldText + "</strong>");
            pos = pos + 9 + boldText.length(); // 9 is the length of "<strong></strong>"
        } else {
            break;
        }
    }
    
    // Handle single asterisks for italic
    pos = 0;
    while ((pos = formatted.indexOf("*", pos)) != -1) {
        int end = formatted.indexOf("*", pos + 1);
        if (end != -1) {
            QString italicText = formatted.mid(pos + 1, end - pos - 1);
            formatted.replace(pos, end - pos + 1, "<em>" + italicText + "</em>");
            pos = pos + 5 + italicText.length(); // 5 is the length of "<em></em>"
        } else {
            break;
        }
    }
    
    // Replace newlines with <br> tags
    formatted.replace("\\n", "<br>");
    formatted.replace("\n", "<br>");
    
    return formatted;
}

void MainWindow::showError(const QString &message) {
    qDebug() << "ERROR: " << message;
    statusLabel->setText("Viga: " + message);
    statusLabel->setStyleSheet("background-color: #ffebee; color: #c62828; padding: 10px; border-radius: 4px;");
    statusLabel->show();
}
EOF

# Loo mainwindow.ui fail
cat <<EOF > mainwindow.ui
<?xml version="1.0" encoding="UTF-8"?>
<ui version="4.0">
 <class>MainWindow</class>
 <widget class="QMainWindow" name="MainWindow">
  <property name="geometry">
   <rect>
    <x>0</x>
    <y>0</y>
    <width>800</width>
    <height>600</height>
   </rect>
  </property>
  <property name="windowTitle">
   <string>$PROJECT_NAME</string>
  </property>
  <widget class="QWidget" name="centralwidget"/>
  <widget class="QMenuBar" name="menubar">
   <property name="geometry">
    <rect>
     <x>0</x>
     <y>0</y>
     <width>800</width>
     <height>22</height>
    </rect>
   </property>
  </widget>
  <widget class="QStatusBar" name="statusbar"/>
 </widget>
 <resources/>
 <connections/>
</ui>
EOF

# Otsi desktop Qt versiooni
echo "Otsin desktop Qt versiooni..."

# Võimalikud asukohad
POSSIBLE_QMAKE_PATHS=(
    "/usr/bin/qmake"
    "/usr/local/bin/qmake"
    "/usr/lib/qt5/bin/qmake"
    "/usr/lib/qt/bin/qmake"
    "$HOME/Qt/"*"/gcc_64/bin/qmake"
    "$HOME/Qt/"*"/clang_64/bin/qmake"
    "$HOME/Qt/"*"/mingw"*"/bin/qmake"
    "$HOME/Qt/"*"/mingw"*"/bin/qmake.exe"
)

DESKTOP_QMAKE=""

# Kontrolli iga võimalikku asukohta
for path in "${POSSIBLE_QMAKE_PATHS[@]}"; do
    # Kasuta globimist, et laiendada *
    for qmake_path in $path; do
        if [ -x "$qmake_path" ]; then
            # Kontrolli, et see on desktop versioon (mitte Android)
            if $qmake_path -query QT_INSTALL_BINS | grep -v "android" > /dev/null 2>&1; then
                DESKTOP_QMAKE="$qmake_path"
                echo "Leitud desktop Qt: $DESKTOP_QMAKE"
                break 2
            fi
        fi
    done
done

# Kui automaatne otsing ebaõnnestus, küsi kasutajalt
if [ -z "$DESKTOP_QMAKE" ]; then
    echo "Ei leidnud automaatselt desktop Qt versiooni."
    read -p "Palun sisesta qmake täistee (nt. ~/Qt/6.5.0/gcc_64/bin/qmake): " DESKTOP_QMAKE
    
    if [ ! -x "$DESKTOP_QMAKE" ]; then
        echo "Viga: '$DESKTOP_QMAKE' ei ole käivitatav fail või ei eksisteeri."
        exit 1
    fi
fi

echo "Kasutan Qt versiooni: $DESKTOP_QMAKE"

# Genereeri Makefile
"$DESKTOP_QMAKE" || {
    echo "Viga: qmake ebaõnnestus. Kontrolli Qt installatsiooni."
    exit 1
}

# Kompileeri projekt
if command -v make &> /dev/null; then
    echo "Kompileerin projekti..."
    make || {
        echo "Viga: kompileerimine ebaõnnestus."
        exit 1
    }
else
    echo "Viga: 'make' käsku ei leitud. Palun installeeri make."
    exit 1
fi

echo "Projekt on loodud ja kompileeritud kausta: $PROJECT_NAME"
echo "Käivitamiseks: ./$PROJECT_NAME"

# Kontrolli, kas kompileeritud fail eksisteerib
if [ -x "./$PROJECT_NAME" ]; then
    read -p "Kas soovid rakendust kohe käivitada? (j/e): " RUN_APP
    if [[ "$RUN_APP" == "j" || "$RUN_APP" == "J" ]]; then
        ./$PROJECT_NAME
    fi
fi