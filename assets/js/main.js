/**
 * AI Business Assistant - Main Frontend Script
 * Theme toggle, mobile sidebar, async chat handler & CSRF utilities
 */

document.addEventListener('DOMContentLoaded', () => {
    // Theme Management: The theme is managed globally by Supreme Owner & Developer
    const themeToggleBtn = document.getElementById('theme-toggle');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const canManage = document.documentElement.getAttribute('data-can-manage-theme') === '1';
            if (canManage) {
                window.location.href = '/owner/themes.php';
            } else {
                alert('🎨 Стиль сайта и приложения устанавливается централизованно Верховным Владельцем (Матвеем) и Разработчиком.');
            }
        });
    }

    // Mobile Sidebar toggle
    const mobileBtn = document.getElementById('mobile-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (mobileBtn && sidebar) {
        mobileBtn.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });
    }

    // AI Assistant Interactive Chat
    const chatForm = document.getElementById('ai-chat-form');
    const chatInput = document.getElementById('ai-chat-input');
    const chatMessages = document.getElementById('chat-messages');

    if (chatForm && chatInput && chatMessages) {
        chatForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const prompt = chatInput.value.trim();
            if (!prompt) return;

            // Render User Bubble
            appendMessage('user', prompt);
            chatInput.value = '';
            chatInput.disabled = true;

            // Render Loading Bubble
            const loadingBubble = appendMessage('ai', '<span class="text-muted">Анализирую данные компании...</span>', true);

            try {
                const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
                const docSelect = document.getElementById('doc_select');
                const docId = docSelect ? docSelect.value : null;

                const response = await fetch('/api/ai_chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        prompt: prompt,
                        document_id: docId,
                        csrf_token: csrfToken
                    })
                });

                const data = await response.json();
                if (data.success) {
                    loadingBubble.innerHTML = formatMarkdown(data.response);
                } else {
                    loadingBubble.innerHTML = `<span class="text-danger">${escapeHtml(data.message || 'Ошибка обработки')}</span>`;
                }
            } catch (err) {
                loadingBubble.innerHTML = `<span class="text-danger">Ошибка сети: ${escapeHtml(err.message)}</span>`;
            } finally {
                chatInput.disabled = false;
                chatInput.focus();
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
        });
    }

    function appendMessage(sender, htmlContent, returnElement = false) {
        const msgDiv = document.createElement('div');
        msgDiv.className = `message ${sender}`;
        const bubble = document.createElement('div');
        bubble.className = 'message-bubble';
        bubble.innerHTML = htmlContent;
        msgDiv.appendChild(bubble);
        chatMessages.appendChild(msgDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        return returnElement ? bubble : msgDiv;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatMarkdown(text) {
        let escaped = escapeHtml(text);
        // Basic markdown formatting
        escaped = escaped.replace(/^### (.*$)/gim, '<h4 style="margin: 8px 0;">$1</h4>');
        escaped = escaped.replace(/^#### (.*$)/gim, '<h5 style="margin: 6px 0;">$1</h5>');
        escaped = escaped.replace(/\*\*(.*?)\*\*/gim, '<strong>$1</strong>');
        escaped = escaped.replace(/\*(.*?)\*/gim, '<em>$1</em>');
        escaped = escaped.replace(/^\> (.*$)/gim, '<blockquote style="border-left: 3px solid var(--primary); padding-left: 8px; margin: 6px 0; color: var(--text-secondary);">$1</blockquote>');
        escaped = escaped.replace(/\n/g, '<br>');
        return escaped;
    }
});

// ==========================================================================
// Page Transition & Action Loaders (Светящийся экран загрузки при действиях)
// ==========================================================================

let topLoaderEl = null;
let overlayEl = null;
let loaderTitleEl = null;
let loaderSubEl = null;
let loaderProgressTimer = null;
let loaderSafetyTimer = null;

function ensureLoaderDOM() {
    if (!topLoaderEl) {
        topLoaderEl = document.getElementById('page-top-loader');
        if (!topLoaderEl && document.body) {
            topLoaderEl = document.createElement('div');
            topLoaderEl.id = 'page-top-loader';
            document.body.appendChild(topLoaderEl);
        }
    }

    if (!overlayEl) {
        overlayEl = document.getElementById('page-transition-overlay');
        if (!overlayEl && document.body) {
            overlayEl = document.createElement('div');
            overlayEl.id = 'page-transition-overlay';
            overlayEl.innerHTML = `
                <div class="page-loader-box">
                    <div class="page-loader-atom">
                        <div class="page-loader-ring"></div>
                        <div class="page-loader-ring"></div>
                        <div class="page-loader-ring"></div>
                        <div class="page-loader-core"></div>
                    </div>
                    <div class="page-loader-title" id="page-loader-title">Загрузка...</div>
                    <div class="page-loader-sub" id="page-loader-sub">Пожалуйста, подождите</div>
                </div>
            `;
            document.body.appendChild(overlayEl);
        }
        loaderTitleEl = document.getElementById('page-loader-title');
        loaderSubEl = document.getElementById('page-loader-sub');
    }
}

window.showPageLoader = function(title = 'Загрузка...', sub = 'Обработка запроса...') {
    ensureLoaderDOM();
    if (loaderTitleEl) loaderTitleEl.textContent = title;
    if (loaderSubEl) loaderSubEl.textContent = sub;

    if (loaderProgressTimer) clearInterval(loaderProgressTimer);
    if (loaderSafetyTimer) clearTimeout(loaderSafetyTimer);

    if (topLoaderEl) {
        topLoaderEl.classList.add('active');
        topLoaderEl.style.width = '15%';
        let currentWidth = 15;
        loaderProgressTimer = setInterval(() => {
            if (currentWidth < 85) {
                currentWidth += Math.floor(Math.random() * 8) + 4;
                if (currentWidth > 85) currentWidth = 85;
                topLoaderEl.style.width = currentWidth + '%';
            }
        }, 150);
    }

    if (overlayEl) {
        overlayEl.classList.add('show');
    }

    // Защитный таймаут: скрыть через 8 сек, если навигация отменена
    loaderSafetyTimer = setTimeout(() => {
        window.hidePageLoader();
    }, 8000);
};

window.hidePageLoader = function() {
    if (loaderProgressTimer) {
        clearInterval(loaderProgressTimer);
        loaderProgressTimer = null;
    }
    if (loaderSafetyTimer) {
        clearTimeout(loaderSafetyTimer);
        loaderSafetyTimer = null;
    }

    if (topLoaderEl) {
        topLoaderEl.style.width = '100%';
        setTimeout(() => {
            topLoaderEl.classList.remove('active');
            setTimeout(() => {
                topLoaderEl.style.width = '0%';
            }, 250);
        }, 150);
    }

    if (overlayEl) {
        overlayEl.classList.remove('show');
    }
};

// Скрытие при переходе назад/вперед из кэша браузера
window.addEventListener('pageshow', () => {
    window.hidePageLoader();
});
window.addEventListener('popstate', () => {
    window.hidePageLoader();
});

function initPageLoaderTriggers() {
    ensureLoaderDOM();

    // Клики по ссылкам
    document.addEventListener('click', (e) => {
        const link = e.target.closest('a');
        if (!link) return;

        // Пропускаем клики с зажатыми модификаторами и правой кнопкой
        if (e.ctrlKey || e.shiftKey || e.metaKey || e.altKey || e.button !== 0) return;

        const href = link.getAttribute('href');
        if (!href) return;

        // Исключения
        if (
            href.startsWith('#') ||
            href.startsWith('javascript:') ||
            href.startsWith('mailto:') ||
            href.startsWith('tel:') ||
            link.hasAttribute('download') ||
            link.getAttribute('target') === '_blank' ||
            link.hasAttribute('data-no-loader')
        ) {
            return;
        }

        // Проверка внешних ссылок
        if (href.startsWith('http://') || href.startsWith('https://')) {
            try {
                const url = new URL(href, window.location.href);
                if (url.origin !== window.location.origin) {
                    return;
                }
            } catch (err) {
                return;
            }
        }

        // Показываем загрузку
        const linkText = link.textContent.trim().replace(/\s+/g, ' ');
        const title = link.getAttribute('data-loader-title') || 'Загрузка страницы...';
        const sub = (linkText && linkText.length < 35) ? linkText : 'Пожалуйста, подождите';
        window.showPageLoader(title, sub);
    });

    // Отправка форм
    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!form || form.getAttribute('target') === '_blank' || form.hasAttribute('data-no-loader')) {
            return;
        }

        const title = form.getAttribute('data-loader-title') || 'Обработка данных...';
        const sub = form.getAttribute('data-loader-sub') || 'Синхронизация с базой данных';
        window.showPageLoader(title, sub);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPageLoaderTriggers);
} else {
    initPageLoaderTriggers();
}
