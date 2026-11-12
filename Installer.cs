using System;
using System.IO;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Windows.Forms;
using System.Threading;

namespace AIAssistantInstaller
{
    // ── Градиентная панель ────────────────────────────────────────────────────
    class GradientPanel : Panel
    {
        private Color _gradFrom = Color.FromArgb(13, 17, 23);
        private Color _gradTo   = Color.FromArgb(22, 27, 34);

        public Color GradFrom { get { return _gradFrom; } set { _gradFrom = value; } }
        public Color GradTo   { get { return _gradTo;   } set { _gradTo   = value; } }

        public GradientPanel() { DoubleBuffered = true; }

        protected override void OnPaintBackground(PaintEventArgs e)
        {
            if (ClientRectangle.Width == 0 || ClientRectangle.Height == 0) return;
            using (LinearGradientBrush b = new LinearGradientBrush(ClientRectangle, _gradFrom, _gradTo, 90f))
                e.Graphics.FillRectangle(b, ClientRectangle);
        }
    }

    // ── Кнопка с закруглёнными углами ────────────────────────────────────────
    class RoundButton : Button
    {
        private bool _hover = false;
        private Color _normalColor = Color.FromArgb(37, 99, 235);
        private Color _hoverColor  = Color.FromArgb(99, 102, 241);

        public Color NormalColor { get { return _normalColor; } set { _normalColor = value; } }
        public Color HoverColor  { get { return _hoverColor;  } set { _hoverColor  = value; } }

        public RoundButton()
        {
            FlatStyle = FlatStyle.Flat;
            FlatAppearance.BorderSize = 0;
            FlatAppearance.MouseOverBackColor = Color.Transparent;
            FlatAppearance.MouseDownBackColor = Color.Transparent;
            Cursor = Cursors.Hand;
            DoubleBuffered = true;
        }

        protected override void OnMouseEnter(EventArgs e) { _hover = true;  Invalidate(); base.OnMouseEnter(e); }
        protected override void OnMouseLeave(EventArgs e) { _hover = false; Invalidate(); base.OnMouseLeave(e); }

        protected override void OnPaint(PaintEventArgs e)
        {
            Graphics g = e.Graphics;
            g.SmoothingMode = SmoothingMode.AntiAlias;
            Color bg = _hover ? Color.FromArgb(Math.Min(255, _hoverColor.R + 15), Math.Min(255, _hoverColor.G + 15), Math.Min(255, _hoverColor.B + 15)) : _normalColor;

            RectangleF rect = new RectangleF(0, 0, Width - 1, Height - 1);
            int d = 20;
            GraphicsPath path = new GraphicsPath();
            path.AddArc(rect.Left, rect.Top, d, d, 180, 90);
            path.AddArc(rect.Right - d, rect.Top, d, d, 270, 90);
            path.AddArc(rect.Right - d, rect.Bottom - d, d, d, 0, 90);
            path.AddArc(rect.Left, rect.Bottom - d, d, d, 90, 90);
            path.CloseFigure();

            using (SolidBrush brush = new SolidBrush(bg))
                g.FillPath(brush, path);
            path.Dispose();

            StringFormat sf = new StringFormat();
            sf.Alignment = StringAlignment.Center;
            sf.LineAlignment = StringAlignment.Center;
            using (SolidBrush tb = new SolidBrush(ForeColor))
                g.DrawString(Text, Font, tb, new RectangleF(0, 0, Width, Height), sf);
            sf.Dispose();
        }
    }

    // ── Прогресс-бар ─────────────────────────────────────────────────────────
    class ModernProgressBar : Control
    {
        private int _value   = 0;
        private int _maximum = 100;

        public int Maximum { get { return _maximum; } set { _maximum = value; Invalidate(); } }
        public int Value
        {
            get { return _value; }
            set { _value = Math.Max(0, Math.Min(_maximum, value)); Invalidate(); }
        }

        public ModernProgressBar() { DoubleBuffered = true; Height = 10; }

        protected override void OnPaint(PaintEventArgs e)
        {
            Graphics g = e.Graphics;
            g.SmoothingMode = SmoothingMode.AntiAlias;
            Rectangle r = ClientRectangle;
            using (SolidBrush bg = new SolidBrush(Color.FromArgb(30, 41, 59)))
                g.FillRectangle(bg, r);

            if (_maximum > 0 && _value > 0)
            {
                int w = (int)((float)Width * _value / _maximum);
                if (w > 0)
                {
                    Rectangle filled = new Rectangle(0, 0, w, Height);
                    using (LinearGradientBrush b = new LinearGradientBrush(
                        new Point(0, 0), new Point(w, 0),
                        Color.FromArgb(99, 102, 241), Color.FromArgb(37, 99, 235)))
                        g.FillRectangle(b, filled);
                }
            }
        }
    }

    // ── Главная форма установщика ─────────────────────────────────────────────
    public class InstallerForm : Form
    {
        private string appDir;

        // Панели
        private GradientPanel pnlMain;
        private Panel pnlSidebar;
        private Panel pnlContent;
        private Panel pnlWelcome;
        private Panel pnlInstall;
        private Panel pnlFinish;

        // Шаги
        private Label[] stepLabels  = new Label[3];
        private Panel[] stepDots    = new Panel[3];
        private int     currentStep = 0;

        // Элементы шагов
        private CheckBox chkDesktopShortcut;
        private CheckBox chkStartMenu;
        private TextBox  txtInstallPath;
        private Label    lblStatus;
        private ModernProgressBar mProgress;
        private TextBox  txtLog;
        private CheckBox chkLaunchNow;

        // Кнопки
        private RoundButton btnNext;
        private RoundButton btnCancel;
        private Label       lblStepCounter;

        // Цвета
        private static readonly Color C_BG      = Color.FromArgb(13, 17, 23);
        private static readonly Color C_SIDEBAR  = Color.FromArgb(10, 14, 20);
        private static readonly Color C_CARD     = Color.FromArgb(22, 27, 34);
        private static readonly Color C_BORDER   = Color.FromArgb(48, 54, 61);
        private static readonly Color C_ACCENT   = Color.FromArgb(37, 99, 235);
        private static readonly Color C_ACCENT2  = Color.FromArgb(99, 102, 241);
        private static readonly Color C_TEXT     = Color.FromArgb(230, 237, 243);
        private static readonly Color C_MUTED    = Color.FromArgb(110, 118, 129);
        private static readonly Color C_SUCCESS  = Color.FromArgb(16, 185, 129);

        public InstallerForm()
        {
            appDir = AppDomain.CurrentDomain.BaseDirectory.TrimEnd('\\', '/');
            if (Path.GetFileName(appDir).Equals("desktop", StringComparison.OrdinalIgnoreCase))
            {
                DirectoryInfo par = Directory.GetParent(appDir);
                if (par != null) appDir = par.FullName;
            }

            SetupForm();
            BuildSidebar();
            BuildContentArea();
            BuildWelcomePanel();
            BuildInstallPanel();
            BuildFinishPanel();
            BuildBottomBar();
            ShowPanel(0);
        }

        private void SetupForm()
        {
            Text = "Установка — AI Business Assistant";
            Size = new Size(820, 560);
            StartPosition = FormStartPosition.CenterScreen;
            FormBorderStyle = FormBorderStyle.FixedSingle;
            MaximizeBox = false;
            BackColor = C_BG;
            ForeColor = C_TEXT;
            Font = new Font("Segoe UI", 9.5f);
            DoubleBuffered = true;

            string icoPath = Path.Combine(appDir, "app.ico");
            if (File.Exists(icoPath)) { try { Icon = new Icon(icoPath); } catch { } }

            pnlMain = new GradientPanel();
            pnlMain.GradFrom = C_BG;
            pnlMain.GradTo   = C_BG;
            pnlMain.Dock     = DockStyle.Fill;
            Controls.Add(pnlMain);
        }

        private void BuildSidebar()
        {
            pnlSidebar = new Panel();
            pnlSidebar.Width     = 220;
            pnlSidebar.Dock      = DockStyle.Left;
            pnlSidebar.BackColor = C_SIDEBAR;
            pnlMain.Controls.Add(pnlSidebar);

            // Правый разделитель
            Panel divider = new Panel();
            divider.Width     = 1;
            divider.Dock      = DockStyle.Right;
            divider.BackColor = C_BORDER;
            pnlSidebar.Controls.Add(divider);

            // Лого-блок
            Panel logoBlock = new Panel();
            logoBlock.Height    = 110;
            logoBlock.Dock      = DockStyle.Top;
            logoBlock.BackColor = Color.Transparent;
            pnlSidebar.Controls.Add(logoBlock);

            string icoPath = Path.Combine(appDir, "app.ico");
            PictureBox picLogo = new PictureBox();
            picLogo.Size     = new Size(52, 52);
            picLogo.Location = new Point(26, 25);
            picLogo.SizeMode = PictureBoxSizeMode.Zoom;
            picLogo.BackColor = Color.Transparent;
            if (File.Exists(icoPath)) { try { picLogo.Image = Image.FromFile(icoPath); } catch { } }
            logoBlock.Controls.Add(picLogo);

            Label lblAppName = new Label();
            lblAppName.Text      = "AI Business\nAssistant";
            lblAppName.Font      = new Font("Segoe UI", 10f, FontStyle.Bold);
            lblAppName.ForeColor = C_TEXT;
            lblAppName.Location  = new Point(84, 28);
            lblAppName.Size      = new Size(120, 44);
            lblAppName.BackColor = Color.Transparent;
            logoBlock.Controls.Add(lblAppName);

            // Шаги
            string[] steps = { "Добро пожаловать", "Установка", "Завершено" };
            int topOffset = 110;

            for (int i = 0; i < steps.Length; i++)
            {
                // Линия до точки (кроме первого)
                if (i > 0)
                {
                    Panel line = new Panel();
                    line.Width     = 2;
                    line.Height    = 24;
                    line.Left      = 34;
                    line.Top       = topOffset;
                    line.BackColor = C_BORDER;
                    pnlSidebar.Controls.Add(line);
                    topOffset += 24;
                }

                // Круг-точка
                Panel dot = new Panel();
                dot.Size      = new Size(26, 26);
                dot.Left      = 23;
                dot.Top       = topOffset;
                dot.BackColor = Color.Transparent;
                int capturedI = i;
                dot.Paint += delegate(object s, PaintEventArgs e) {
                    e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
                    Color c = (capturedI == currentStep) ? C_ACCENT : C_BORDER;
                    using (SolidBrush b = new SolidBrush(c))
                        e.Graphics.FillEllipse(b, 0, 0, 25, 25);
                    // Номер
                    string num = (capturedI + 1).ToString();
                    using (Font f = new Font("Segoe UI", 8f, FontStyle.Bold))
                    using (SolidBrush tb = new SolidBrush(Color.White))
                    {
                        StringFormat sf = new StringFormat();
                        sf.Alignment = StringAlignment.Center;
                        sf.LineAlignment = StringAlignment.Center;
                        e.Graphics.DrawString(num, f, tb, new RectangleF(0, 0, 25, 25), sf);
                        sf.Dispose();
                    }
                };
                stepDots[i] = dot;
                pnlSidebar.Controls.Add(dot);

                // Подпись
                Label lbl = new Label();
                lbl.Text      = steps[i];
                lbl.Font      = new Font("Segoe UI", 9.5f);
                lbl.ForeColor = (i == 0) ? C_TEXT : C_MUTED;
                lbl.Location  = new Point(58, topOffset + 4);
                lbl.AutoSize  = true;
                lbl.BackColor = Color.Transparent;
                stepLabels[i] = lbl;
                pnlSidebar.Controls.Add(lbl);

                topOffset += 44;
            }

            // Версия
            Label lblVer = new Label();
            lblVer.Text      = "v2.0 Beta";
            lblVer.Font      = new Font("Segoe UI", 8f);
            lblVer.ForeColor = C_MUTED;
            lblVer.Dock      = DockStyle.Bottom;
            lblVer.Height    = 30;
            lblVer.TextAlign = ContentAlignment.MiddleCenter;
            lblVer.BackColor = Color.Transparent;
            pnlSidebar.Controls.Add(lblVer);
        }

        private void BuildContentArea()
        {
            pnlContent = new Panel();
            pnlContent.Dock      = DockStyle.Fill;
            pnlContent.BackColor = Color.Transparent;
            pnlMain.Controls.Add(pnlContent);
            pnlContent.BringToFront();
        }

        // ── Шаг 1: Добро пожаловать ──────────────────────────────────────────
        private void BuildWelcomePanel()
        {
            pnlWelcome = new Panel();
            pnlWelcome.Dock      = DockStyle.Fill;
            pnlWelcome.BackColor = Color.Transparent;
            pnlWelcome.Visible   = false;
            pnlContent.Controls.Add(pnlWelcome);

            // Заголовок
            Label lblTitle = new Label();
            lblTitle.Text      = "Добро пожаловать!";
            lblTitle.Font      = new Font("Segoe UI", 17f, FontStyle.Bold);
            lblTitle.ForeColor = C_TEXT;
            lblTitle.Location  = new Point(32, 32);
            lblTitle.AutoSize  = true;
            lblTitle.BackColor = Color.Transparent;
            pnlWelcome.Controls.Add(lblTitle);

            Label lblSub = new Label();
            lblSub.Text      = "Мастер установки AI Business Assistant v2.0";
            lblSub.Font      = new Font("Segoe UI", 9.5f);
            lblSub.ForeColor = C_MUTED;
            lblSub.Location  = new Point(32, 68);
            lblSub.AutoSize  = true;
            lblSub.BackColor = Color.Transparent;
            pnlWelcome.Controls.Add(lblSub);

            // Разделитель
            Panel sep = new Panel();
            sep.Location  = new Point(32, 98);
            sep.Size      = new Size(528, 1);
            sep.BackColor = C_BORDER;
            pnlWelcome.Controls.Add(sep);

            // Карточка описания
            Panel descCard = MakeCard(32, 114, 528, 88);
            pnlWelcome.Controls.Add(descCard);

            Label descText = new Label();
            descText.Text      = "Корпоративная платформа для управления бизнесом:\nCRM, задачи, аналитика, чаты, касса и ИИ-ассистент.\n\nУстановка займёт меньше минуты.";
            descText.Font      = new Font("Segoe UI", 9f);
            descText.ForeColor = Color.FromArgb(170, 185, 200);
            descText.Location  = new Point(16, 12);
            descText.Size      = new Size(496, 68);
            descText.BackColor = Color.Transparent;
            descCard.Controls.Add(descText);

            // Каталог
            Label lblDirTitle = new Label();
            lblDirTitle.Text      = "КАТАЛОГ ПРИЛОЖЕНИЯ";
            lblDirTitle.Font      = new Font("Segoe UI", 7.5f, FontStyle.Bold);
            lblDirTitle.ForeColor = C_MUTED;
            lblDirTitle.Location  = new Point(32, 220);
            lblDirTitle.AutoSize  = true;
            lblDirTitle.BackColor = Color.Transparent;
            pnlWelcome.Controls.Add(lblDirTitle);

            Panel pathCard = MakeCard(32, 240, 528, 44);
            pnlWelcome.Controls.Add(pathCard);

            txtInstallPath = new TextBox();
            txtInstallPath.Text        = appDir;
            txtInstallPath.ReadOnly    = true;
            txtInstallPath.Location    = new Point(12, 10);
            txtInstallPath.Size        = new Size(502, 24);
            txtInstallPath.BackColor   = C_CARD;
            txtInstallPath.ForeColor   = Color.FromArgb(56, 189, 248);
            txtInstallPath.BorderStyle = BorderStyle.None;
            txtInstallPath.Font        = new Font("Consolas", 8.5f);
            pathCard.Controls.Add(txtInstallPath);

            // Опции
            Label lblOpts = new Label();
            lblOpts.Text      = "ПАРАМЕТРЫ УСТАНОВКИ";
            lblOpts.Font      = new Font("Segoe UI", 7.5f, FontStyle.Bold);
            lblOpts.ForeColor = C_MUTED;
            lblOpts.Location  = new Point(32, 302);
            lblOpts.AutoSize  = true;
            lblOpts.BackColor = Color.Transparent;
            pnlWelcome.Controls.Add(lblOpts);

            chkDesktopShortcut = MakeCheck("Создать ярлык на Рабочем столе", 36, 326, true);
            chkStartMenu       = MakeCheck("Добавить в меню «Пуск»", 36, 356, true);
            pnlWelcome.Controls.Add(chkDesktopShortcut);
            pnlWelcome.Controls.Add(chkStartMenu);
        }

        // ── Шаг 2: Установка ─────────────────────────────────────────────────
        private void BuildInstallPanel()
        {
            pnlInstall = new Panel();
            pnlInstall.Dock      = DockStyle.Fill;
            pnlInstall.BackColor = Color.Transparent;
            pnlInstall.Visible   = false;
            pnlContent.Controls.Add(pnlInstall);

            Label lblTitle = new Label();
            lblTitle.Text      = "Установка...";
            lblTitle.Font      = new Font("Segoe UI", 17f, FontStyle.Bold);
            lblTitle.ForeColor = C_TEXT;
            lblTitle.Location  = new Point(32, 32);
            lblTitle.AutoSize  = true;
            lblTitle.BackColor = Color.Transparent;
            pnlInstall.Controls.Add(lblTitle);

            Label lblSub = new Label();
            lblSub.Text      = "Настройка компонентов платформы...";
            lblSub.Font      = new Font("Segoe UI", 9.5f);
            lblSub.ForeColor = C_MUTED;
            lblSub.Location  = new Point(32, 68);
            lblSub.AutoSize  = true;
            lblSub.BackColor = Color.Transparent;
            pnlInstall.Controls.Add(lblSub);

            Panel sep = new Panel();
            sep.Location  = new Point(32, 98);
            sep.Size      = new Size(528, 1);
            sep.BackColor = C_BORDER;
            pnlInstall.Controls.Add(sep);

            lblStatus = new Label();
            lblStatus.Text      = "Инициализация...";
            lblStatus.Font      = new Font("Segoe UI", 10f, FontStyle.Bold);
            lblStatus.ForeColor = Color.FromArgb(56, 189, 248);
            lblStatus.Location  = new Point(32, 114);
            lblStatus.Size      = new Size(528, 24);
            lblStatus.BackColor = Color.Transparent;
            pnlInstall.Controls.Add(lblStatus);

            mProgress = new ModernProgressBar();
            mProgress.Location = new Point(32, 146);
            mProgress.Size     = new Size(528, 10);
            mProgress.Maximum  = 100;
            mProgress.Value    = 0;
            pnlInstall.Controls.Add(mProgress);

            Panel logCard = MakeCard(32, 168, 528, 220);
            pnlInstall.Controls.Add(logCard);

            txtLog = new TextBox();
            txtLog.Location    = new Point(12, 10);
            txtLog.Size        = new Size(502, 200);
            txtLog.Multiline   = true;
            txtLog.ScrollBars  = ScrollBars.Vertical;
            txtLog.ReadOnly    = true;
            txtLog.BackColor   = Color.FromArgb(10, 14, 20);
            txtLog.ForeColor   = Color.FromArgb(140, 160, 180);
            txtLog.BorderStyle = BorderStyle.None;
            txtLog.Font        = new Font("Consolas", 8.5f);
            logCard.Controls.Add(txtLog);
        }

        // ── Шаг 3: Завершено ──────────────────────────────────────────────────
        private void BuildFinishPanel()
        {
            pnlFinish = new Panel();
            pnlFinish.Dock      = DockStyle.Fill;
            pnlFinish.BackColor = Color.Transparent;
            pnlFinish.Visible   = false;
            pnlContent.Controls.Add(pnlFinish);

            Label icoLabel = new Label();
            icoLabel.Text      = "v";
            icoLabel.Font      = new Font("Wingdings", 42f, FontStyle.Bold);
            icoLabel.ForeColor = C_SUCCESS;
            icoLabel.Location  = new Point(32, 20);
            icoLabel.AutoSize  = true;
            icoLabel.BackColor = Color.Transparent;
            pnlFinish.Controls.Add(icoLabel);

            Label lblTitle = new Label();
            lblTitle.Text      = "Установка завершена!";
            lblTitle.Font      = new Font("Segoe UI", 20f, FontStyle.Bold);
            lblTitle.ForeColor = C_TEXT;
            lblTitle.Location  = new Point(32, 110);
            lblTitle.AutoSize  = true;
            lblTitle.BackColor = Color.Transparent;
            pnlFinish.Controls.Add(lblTitle);

            Label lblSub = new Label();
            lblSub.Text      = "AI Business Assistant готов к работе. Ярлыки созданы.";
            lblSub.Font      = new Font("Segoe UI", 10f);
            lblSub.ForeColor = C_MUTED;
            lblSub.Location  = new Point(32, 150);
            lblSub.AutoSize  = true;
            lblSub.BackColor = Color.Transparent;
            pnlFinish.Controls.Add(lblSub);

            Panel sep = new Panel();
            sep.Location  = new Point(32, 190);
            sep.Size      = new Size(528, 1);
            sep.BackColor = C_BORDER;
            pnlFinish.Controls.Add(sep);

            chkLaunchNow = new CheckBox();
            chkLaunchNow.Text      = "Запустить AI Business Assistant сейчас";
            chkLaunchNow.Location  = new Point(36, 208);
            chkLaunchNow.Size      = new Size(440, 28);
            chkLaunchNow.Checked   = true;
            chkLaunchNow.ForeColor = C_TEXT;
            chkLaunchNow.BackColor = Color.Transparent;
            chkLaunchNow.Font      = new Font("Segoe UI", 10.5f, FontStyle.Bold);
            chkLaunchNow.Cursor    = Cursors.Hand;
            pnlFinish.Controls.Add(chkLaunchNow);
        }

        // ── Нижняя панель с кнопками ─────────────────────────────────────────
        private void BuildBottomBar()
        {
            Panel bottomBar = new Panel();
            bottomBar.Height    = 64;
            bottomBar.Dock      = DockStyle.Bottom;
            bottomBar.BackColor = Color.FromArgb(10, 14, 20);
            pnlMain.Controls.Add(bottomBar);
            bottomBar.BringToFront();

            Panel topLine = new Panel();
            topLine.Height    = 1;
            topLine.Dock      = DockStyle.Top;
            topLine.BackColor = C_BORDER;
            bottomBar.Controls.Add(topLine);

            // Кнопки через Anchor+Dock: прижимаем к правому краю
            Panel btnArea = new Panel();
            btnArea.Width     = 290;
            btnArea.Height    = 64;
            btnArea.Dock      = DockStyle.Right;
            btnArea.BackColor = Color.Transparent;
            bottomBar.Controls.Add(btnArea);

            btnNext = new RoundButton();
            btnNext.Text        = "Начать установку";
            btnNext.Size        = new Size(165, 38);
            btnNext.Location    = new Point(118, 13);
            btnNext.Font        = new Font("Segoe UI", 10f, FontStyle.Bold);
            btnNext.ForeColor   = Color.White;
            btnNext.NormalColor = C_ACCENT;
            btnNext.HoverColor  = C_ACCENT2;
            btnNext.Click      += BtnNext_Click;
            btnArea.Controls.Add(btnNext);

            btnCancel = new RoundButton();
            btnCancel.Text        = "Отмена";
            btnCancel.Size        = new Size(104, 38);
            btnCancel.Location    = new Point(8, 13);
            btnCancel.Font        = new Font("Segoe UI", 9.5f);
            btnCancel.ForeColor   = Color.FromArgb(180, 190, 200);
            btnCancel.NormalColor = Color.FromArgb(30, 41, 59);
            btnCancel.HoverColor  = Color.FromArgb(48, 61, 78);
            btnCancel.Click      += delegate(object s, EventArgs e) { this.Close(); };
            btnArea.Controls.Add(btnCancel);

            lblStepCounter = new Label();
            lblStepCounter.Text      = "Шаг 1 из 3";
            lblStepCounter.Font      = new Font("Segoe UI", 9f);
            lblStepCounter.ForeColor = C_MUTED;
            lblStepCounter.Dock      = DockStyle.Left;
            lblStepCounter.Width     = 150;
            lblStepCounter.TextAlign = ContentAlignment.MiddleLeft;
            lblStepCounter.Padding   = new Padding(32, 0, 0, 0);
            lblStepCounter.BackColor = Color.Transparent;
            bottomBar.Controls.Add(lblStepCounter);
        }

        // ── Навигация ─────────────────────────────────────────────────────────
        private void ShowPanel(int step)
        {
            currentStep = step;
            pnlWelcome.Visible = (step == 0);
            pnlInstall.Visible = (step == 1);
            pnlFinish.Visible  = (step == 2);

            for (int i = 0; i < stepLabels.Length; i++)
            {
                stepLabels[i].ForeColor = (i == step) ? C_TEXT : C_MUTED;
                stepLabels[i].Font      = new Font("Segoe UI", 9.5f, (i == step) ? FontStyle.Bold : FontStyle.Regular);
                stepDots[i].Invalidate();
            }

            lblStepCounter.Text = "Шаг " + (step + 1) + " из 3";

            if (step == 0) { btnNext.Text = "Начать установку"; btnNext.Enabled = true;  btnCancel.Visible = true;  }
            if (step == 1) { btnNext.Text = "Установка...";     btnNext.Enabled = false; btnCancel.Visible = false; }
            if (step == 2) { btnNext.Text = "Завершить";        btnNext.Enabled = true;  btnCancel.Visible = false; }
        }

        private void BtnNext_Click(object sender, EventArgs e)
        {
            if (currentStep == 0)
            {
                ShowPanel(1);
                Thread worker = new Thread(RunInstallation);
                worker.IsBackground = true;
                worker.Start();
            }
            else if (currentStep == 2)
            {
                LaunchApp();
                this.Close();
            }
        }

        // ── Логика установки ─────────────────────────────────────────────────
        private void AppendLog(string msg)
        {
            if (InvokeRequired) { Invoke(new Action<string>(AppendLog), msg); return; }
            txtLog.AppendText("[" + DateTime.Now.ToString("HH:mm:ss") + "] " + msg + "\r\n");
        }

        private void SetStatus(string text, int progress)
        {
            if (InvokeRequired) { Invoke(new Action<string, int>(SetStatus), text, progress); return; }
            lblStatus.Text = text;
            mProgress.Value = progress;
        }

        private void RunInstallation()
        {
            try
            {
                SetStatus("Создание каталогов...", 10);
                AppendLog("Путь: " + appDir);
                string[] subdirs = { "uploads", "uploads\\voice_messages", "uploads\\chat_files", "uploads\\call_recordings", "scratch" };
                foreach (string d in subdirs)
                {
                    string full = Path.Combine(appDir, d);
                    if (!Directory.Exists(full)) { Directory.CreateDirectory(full); AppendLog("+ " + d); }
                }
                Thread.Sleep(300);

                SetStatus("Конфигурация (.env)...", 28);
                string env   = Path.Combine(appDir, ".env");
                string envEx = Path.Combine(appDir, ".env.example");
                if (!File.Exists(env) && File.Exists(envEx)) { File.Copy(envEx, env); AppendLog("Создан .env из шаблона"); }
                else AppendLog(".env: OK");
                Thread.Sleep(300);

                SetStatus("Проверка PHP...", 44);
                bool hasPhp = File.Exists(@"C:\xampp\php\php.exe");
                AppendLog("PHP: " + (hasPhp ? "найден (XAMPP)" : "используется системный"));
                Thread.Sleep(300);

                SetStatus("Проверка MySQL...", 58);
                bool hasMySql = File.Exists(@"C:\xampp\mysql\bin\mysqld.exe");
                AppendLog("MySQL: " + (hasMySql ? "найден (XAMPP)" : "используется системный"));
                Thread.Sleep(300);

                SetStatus("Проверка Electron...", 70);
                string electronExe = Path.Combine(appDir, "desktop\\node_modules\\electron\\dist\\electron.exe");
                AppendLog("Electron: " + (File.Exists(electronExe) ? "найден" : "не найден"));
                Thread.Sleep(300);

                SetStatus("Создание деинсталлятора...", 78);
                CreateUninstaller();
                AppendLog("Создан Uninstall.bat");
                Thread.Sleep(200);

                if (chkDesktopShortcut.Checked)
                {
                    SetStatus("Ярлык на Рабочем столе...", 85);
                    string dest = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.Desktop), "AI Business Assistant.lnk");
                    CreateShortcut(dest);
                    AppendLog("Ярлык создан на Рабочем столе");
                    Thread.Sleep(200);
                }

                if (chkStartMenu.Checked)
                {
                    SetStatus("Ярлык в меню Пуск...", 93);
                    string sDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.Programs), "AI Business Assistant");
                    if (!Directory.Exists(sDir)) Directory.CreateDirectory(sDir);
                    CreateShortcut(Path.Combine(sDir, "AI Business Assistant.lnk"));
                    AppendLog("Ярлык создан в меню Пуск");
                    Thread.Sleep(200);
                }

                SetStatus("Готово!", 100);
                AppendLog("------------------------------------");
                AppendLog("Установка успешно завершена!");
                Thread.Sleep(600);

                Invoke(new Action(delegate() { ShowPanel(2); }));
            }
            catch (Exception ex)
            {
                AppendLog("ОШИБКА: " + ex.Message);
                Invoke(new Action(delegate() {
                    MessageBox.Show("Ошибка: " + ex.Message, "Ошибка установки", MessageBoxButtons.OK, MessageBoxIcon.Error);
                }));
            }
        }

        private void CreateShortcut(string shortcutPath)
        {
            string electronExe = Path.Combine(appDir, "desktop\\node_modules\\electron\\dist\\electron.exe");
            string desktopDir  = Path.Combine(appDir, "desktop");
            string launcherExe = Path.Combine(appDir, "AI-Business-Assistant.exe");
            string iconPath    = Path.Combine(appDir, "app.ico");

            string target, argsPath, workDir;
            if (File.Exists(electronExe))
            {
                target   = electronExe;
                argsPath = desktopDir;   // без кавычек — VBScript сам обернёт через Chr(34)
                workDir  = desktopDir;
            }
            else if (File.Exists(launcherExe))
            {
                target   = launcherExe;
                argsPath = "";
                workDir  = appDir;
            }
            else
            {
                target   = Path.Combine(appDir, "LAUNCH.bat");
                argsPath = "";
                workDir  = appDir;
            }

            // Формируем строку аргументов для VBScript: Chr(34) избегает проблем с кавычками внутри VBS-строк
            string vbsArgsLine;
            if (argsPath.Length > 0)
                vbsArgsLine = "lnk.Arguments=Chr(34) & \"" + argsPath.Replace("\\", "\\\\") + "\" & Chr(34)\n";
            else
                vbsArgsLine = "lnk.Arguments=\"\"\n";

            string iconLine = File.Exists(iconPath) ? "lnk.IconLocation=\"" + iconPath.Replace("\\", "\\\\") + "\"\n" : "";
            string vbs = Path.GetTempFileName() + ".vbs";
            File.WriteAllText(vbs,
                "Set s=WScript.CreateObject(\"WScript.Shell\")\n" +
                "Set lnk=s.CreateShortcut(\"" + shortcutPath.Replace("\\", "\\\\") + "\")\n" +
                "lnk.TargetPath=\"" + target.Replace("\\", "\\\\") + "\"\n" +
                vbsArgsLine +
                "lnk.WorkingDirectory=\"" + workDir.Replace("\\", "\\\\") + "\"\n" +
                "lnk.Description=\"AI Business Assistant\"\n" +
                iconLine +
                "lnk.Save\n",
                System.Text.Encoding.Default);

            ProcessStartInfo psi = new ProcessStartInfo("wscript.exe", "\"" + vbs + "\"");
            psi.CreateNoWindow   = true;
            psi.UseShellExecute  = false;
            Process p = Process.Start(psi);
            p.WaitForExit();
            try { File.Delete(vbs); } catch { }
        }

        private void CreateUninstaller()
        {
            string desktop   = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.Desktop), "AI Business Assistant.lnk");
            string startMenu = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.Programs), "AI Business Assistant");
            File.WriteAllText(Path.Combine(appDir, "Uninstall.bat"),
                "@echo off\nchcp 65001 >nul\necho Удаление AI Business Assistant...\n" +
                "if exist \"" + desktop + "\" del /f /q \"" + desktop + "\"\n" +
                "if exist \"" + startMenu + "\" rmdir /s /q \"" + startMenu + "\"\n" +
                "echo Готово.\ntimeout /t 3 >nul\n",
                System.Text.Encoding.GetEncoding(65001));
        }

        private void LaunchApp()
        {
            if (!chkLaunchNow.Checked) return;
            try
            {
                string electronExe = Path.Combine(appDir, "desktop\\node_modules\\electron\\dist\\electron.exe");
                string desktopDir  = Path.Combine(appDir, "desktop");
                string launcherExe = Path.Combine(appDir, "AI-Business-Assistant.exe");

                if (File.Exists(electronExe))
                {
                    ProcessStartInfo psi = new ProcessStartInfo(electronExe, "\"" + desktopDir + "\"");
                    psi.WorkingDirectory = desktopDir;
                    psi.UseShellExecute  = false;
                    Process.Start(psi);
                }
                else if (File.Exists(launcherExe))
                {
                    Process.Start(launcherExe);
                }
            }
            catch { }
        }

        // ── Вспомогательные ───────────────────────────────────────────────────
        private Panel MakeCard(int x, int y, int w, int h)
        {
            Panel card = new Panel();
            card.Location  = new Point(x, y);
            card.Size      = new Size(w, h);
            card.BackColor = C_CARD;
            card.Paint    += delegate(object s, PaintEventArgs e) {
                ControlPaint.DrawBorder(e.Graphics, card.ClientRectangle,
                    C_BORDER, 1, ButtonBorderStyle.Solid,
                    C_BORDER, 1, ButtonBorderStyle.Solid,
                    C_BORDER, 1, ButtonBorderStyle.Solid,
                    C_BORDER, 1, ButtonBorderStyle.Solid);
            };
            return card;
        }

        private CheckBox MakeCheck(string text, int x, int y, bool chk)
        {
            CheckBox cb = new CheckBox();
            cb.Text      = text;
            cb.Location  = new Point(x, y);
            cb.Size      = new Size(440, 24);
            cb.Checked   = chk;
            cb.ForeColor = C_TEXT;
            cb.BackColor = Color.Transparent;
            cb.Font      = new Font("Segoe UI", 9.5f);
            cb.Cursor    = Cursors.Hand;
            return cb;
        }

        [System.Runtime.InteropServices.DllImport("user32.dll")]
        private static extern bool SetProcessDPIAware();

        [STAThread]
        public static void Main()
        {
            // Включаем DPI-aware — убирает пиксельность на экранах с высоким DPI
            try { SetProcessDPIAware(); } catch { }
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            Application.Run(new InstallerForm());
        }
    }
}
