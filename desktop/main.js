// Убиваем стандартное меню Electron ДО создания любого окна
process.env.ELECTRON_NO_DEFAULT_MENU = '1';
const { app, BrowserWindow, dialog, Menu } = require('electron');
const path = require('path');
const http = require('http');
const fs = require('fs');
const { spawn, exec } = require('child_process');

// Полностью отключаем стандартное меню Electron (File, Edit, View, Window) во всём приложении
Menu.setApplicationMenu(null);

// Перехватываем создание ЛЮБОГО окна — убираем меню принудительно
app.on('browser-window-created', (_, win) => {
    win.setMenu(null);
    win.setMenuBarVisibility(false);
});

const logFile = path.join(__dirname, 'app.log');
function log(msg) {
    try { fs.appendFileSync(logFile, `[${new Date().toISOString()}] ${msg}\n`); } catch(e){}
}
process.on('uncaughtException', (err) => log('UNCAUGHT: ' + (err.stack || err)));
process.on('unhandledRejection', (err) => log('REJECTION: ' + (err.stack || err)));
log('App started');

let mainWindow = null;
let phpProcess = null;

const PORT = 8000;
const HOST = '127.0.0.1';

// Путь к корню приложения (папка выше desktop/)
const ROOT_DIR = path.resolve(__dirname, '..');

// Ищем PHP в стандартных местах
function findPhp() {
    const candidates = [
        'C:\\xampp\\php\\php.exe',
        'C:\\php\\php.exe',
        'C:\\php8\\php.exe',
        'C:\\php82\\php.exe',
        'C:\\laragon\\bin\\php\\php-8.2.0-Win32-vs16-x64\\php.exe',
        'C:\\wamp64\\bin\\php\\php8.2.26\\php.exe',
    ];
    for (const p of candidates) {
        if (fs.existsSync(p)) { log('PHP found: ' + p); return p; }
    }
    // Попробуем найти через PATH
    log('PHP not found in default locations, trying PATH');
    return 'php'; // fallback: если php в PATH
}

// Ищем mysqld
function findMysqld() {
    const candidates = [
        'C:\\xampp\\mysql\\bin\\mysqld.exe',
        'C:\\MySQL\\bin\\mysqld.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqld.exe',
        'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysqld.exe',
    ];
    for (const p of candidates) {
        if (fs.existsSync(p)) { log('MySQL found: ' + p); return p; }
    }
    return null;
}

function findMysqlIni() {
    const candidates = [
        'C:\\xampp\\mysql\\bin\\my.ini',
        'C:\\MySQL\\my.ini',
        'C:\\ProgramData\\MySQL\\MySQL Server 8.0\\my.ini',
    ];
    for (const p of candidates) { if (fs.existsSync(p)) return p; }
    return null;
}

function ensureMySQL() {
    return new Promise((resolve) => {
        exec('tasklist /FI "IMAGENAME eq mysqld.exe"', (err, stdout) => {
            if (stdout && stdout.toLowerCase().includes('mysqld.exe')) {
                log('MySQL already running');
                resolve();
                return;
            }
            const mysqld = findMysqld();
            if (!mysqld) {
                log('MySQL not found — assuming external MySQL running');
                resolve();
                return;
            }
            const ini = findMysqlIni();
            const args = ini ? ['--defaults-file=' + ini, '--standalone'] : ['--standalone'];
            try {
                spawn(mysqld, args, { detached: true, stdio: 'ignore' }).unref();
                log('MySQL started');
            } catch (e) { log('MySQL start error: ' + e.message); }
            setTimeout(resolve, 2000);
        });
    });
}

function checkServerReady() {
    return new Promise((resolve) => {
        const req = http.get(`http://${HOST}:${PORT}/index.php`, (res) => {
            resolve(true);
        });
        req.on('error', () => resolve(false));
        req.setTimeout(800, () => { try { req.destroy(); } catch(e){} resolve(false); });
    });
}

function startPhpServer() {
    return new Promise((resolve) => {
        checkServerReady().then((isAlive) => {
            if (isAlive) { log('PHP server already running'); resolve(); return; }

            const phpExe = findPhp();
            log('Starting PHP server: ' + phpExe + ' -S ' + HOST + ':' + PORT + ' -t ' + ROOT_DIR);

            phpProcess = spawn(phpExe, ['-d', 'extension=zip', '-S', `${HOST}:${PORT}`, '-t', ROOT_DIR], {
                cwd: ROOT_DIR,
                stdio: 'ignore'
            });

            phpProcess.on('error', (err) => log('PHP error: ' + err.message));

            let attempts = 0;
            const interval = setInterval(async () => {
                attempts++;
                const ready = await checkServerReady();
                if (ready || attempts > 60) {
                    clearInterval(interval);
                    log(ready ? 'PHP server ready' : 'PHP server timeout after ' + attempts + ' attempts');
                    resolve();
                }
            }, 300);
        });
    });
}

function createWindow() {
    Menu.setApplicationMenu(null);

    mainWindow = new BrowserWindow({
        width: 1366,
        height: 850,
        minWidth: 1024,
        minHeight: 650,
        title: 'AI Business Assistant',
        icon: path.join(__dirname, 'icon.ico'),
        backgroundColor: '#0d1117',
        autoHideMenuBar: false,
        show: false,
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            spellcheck: false
        }
    });

    // Полностью убираем меню — нет ни File/Edit/View/Window, ни Alt-триггера
    mainWindow.setMenu(null);
    mainWindow.menuBarVisible = false;
    mainWindow.setMenuBarVisibility(false);
    mainWindow.loadFile(path.join(__dirname, 'splash.html'));
    mainWindow.once('ready-to-show', () => { mainWindow.show(); });

    // Инъекция CSS для отключения перетаскивания ссылок и браузерных фантомов с адресом localhost
    mainWindow.webContents.on('did-finish-load', () => {
        mainWindow.webContents.insertCSS(`
            *, *::before, *::after {
                -webkit-user-drag: none !important;
                user-drag: none !important;
            }
            .sidebar, .sidebar-link, .topbar, nav, header {
                -webkit-user-select: none !important;
                user-select: none !important;
            }
        `).catch(() => {});
    });

    // Навигация мышью Alt+Left/Right
    mainWindow.webContents.on('app-command', (e, cmd) => {
        if (cmd === 'browser-backward' && mainWindow.webContents.canGoBack())
            mainWindow.webContents.goBack();
        else if (cmd === 'browser-forward' && mainWindow.webContents.canGoForward())
            mainWindow.webContents.goForward();
    });

    mainWindow.webContents.on('before-input-event', (event, input) => {
        if (input.type === 'keyDown' && input.alt) {
            if (input.key === 'ArrowLeft' && mainWindow.webContents.canGoBack()) {
                mainWindow.webContents.goBack(); event.preventDefault();
            } else if (input.key === 'ArrowRight' && mainWindow.webContents.canGoForward()) {
                mainWindow.webContents.goForward(); event.preventDefault();
            }
        }
    });

    mainWindow.on('closed', () => { mainWindow = null; });
}

app.whenReady().then(async () => {
    createWindow();
    await ensureMySQL();
    await startPhpServer();
    if (mainWindow) {
        mainWindow.loadURL(`http://${HOST}:${PORT}`);
        log('Loaded app URL');
    }

    app.on('activate', () => {
        if (BrowserWindow.getAllWindows().length === 0) createWindow();
    });
});

app.on('window-all-closed', () => {
    log('App closing');
    if (phpProcess) { try { phpProcess.kill(); } catch(e){} }
    app.quit();
});
