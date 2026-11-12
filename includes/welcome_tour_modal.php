<?php
declare(strict_types=1);

/**
 * AI Business Assistant - Welcome & Educational Onboarding Modal
 * Обучающее интерактивное окно с презентацией проекта и ключевой функциональности
 */

$currentAuthUser = Auth::user();
$tourUserId = (int)($currentAuthUser['id'] ?? 0);
$tourUserName = htmlspecialchars($currentAuthUser['first_name'] ?? 'Пользователь');

$shouldForceTour = !empty($_SESSION['new_account_tour']);
if ($shouldForceTour) {
    unset($_SESSION['new_account_tour']); // Сбрасываем флаг, чтобы показывалось строго однократно при регистрации
}

// Если идет глобальный блок тех. работ, окно обучения не перебивает системное сообщение
$isMaintBlocking = Auth::isMaintenanceMode() && !Auth::isUserRoleAllowedInMaintenance($currentAuthUser);
?>

<!-- Стили обучающего окна -->
<style>
#welcomeTourModal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 999980;
    background: rgba(3, 7, 18, 0.88);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    padding: 16px;
    overflow-y: auto;
    animation: tourBackdropIn 0.3s ease;
}

@keyframes tourBackdropIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes tourCardDrop {
    from { opacity: 0; transform: scale(0.94) translateY(18px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}

@keyframes tourShimmerBar {
    0% { background-position: 0% 50%; }
    100% { background-position: 200% 50%; }
}

@keyframes tourPulseGlow {
    0%, 100% { box-shadow: 0 0 20px rgba(139, 92, 246, 0.35), 0 25px 60px rgba(0,0,0,0.8); }
    50% { box-shadow: 0 0 32px rgba(139, 92, 246, 0.55), 0 25px 60px rgba(0,0,0,0.9); }
}

.tour-card-container {
    position: relative;
    width: 100%;
    max-width: 860px;
    margin: 20px auto;
    background: linear-gradient(165deg, rgba(17, 24, 39, 0.98), rgba(15, 23, 42, 0.99));
    border: 1.5px solid rgba(139, 92, 246, 0.45);
    border-radius: 24px;
    overflow: hidden;
    animation: tourCardDrop 0.35s cubic-bezier(0.16, 1, 0.3, 1), tourPulseGlow 5s infinite ease-in-out;
    box-shadow: 0 25px 60px rgba(0,0,0,0.8);
    color: #f8fafc;
}

.tour-step-pill {
    cursor: pointer;
    padding: 8px 14px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    color: #94a3b8;
    font-size: 12px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.2s ease;
    user-select: none;
    white-space: nowrap;
}

.tour-step-pill:hover {
    background: rgba(255, 255, 255, 0.08);
    color: #f1f5f9;
}

.tour-step-pill.active {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.28), rgba(168, 85, 247, 0.28));
    border-color: rgba(168, 85, 247, 0.65);
    color: #e9d5ff;
    box-shadow: 0 0 15px rgba(168, 85, 247, 0.3);
}

.tour-feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 14px;
    margin-top: 18px;
}

.tour-feature-card {
    background: rgba(255, 255, 255, 0.025);
    border: 1px solid rgba(255, 255, 255, 0.07);
    border-radius: 16px;
    padding: 16px;
    transition: all 0.25s ease;
}

.tour-feature-card:hover {
    transform: translateY(-2px);
    background: rgba(255, 255, 255, 0.045);
    border-color: rgba(168, 85, 247, 0.4);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
}

.tour-icon-box {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    margin-bottom: 12px;
}

.tour-slide {
    display: none;
    animation: tourSlideFade 0.3s ease;
}

.tour-slide.active {
    display: block;
}

@keyframes tourSlideFade {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

.tour-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    cursor: pointer;
    transition: all 0.2s ease;
}

.tour-dot.active {
    width: 24px;
    border-radius: 10px;
    background: linear-gradient(90deg, #6366f1, #a855f7);
    box-shadow: 0 0 10px rgba(168, 85, 247, 0.6);
}
</style>

<!-- Модальное окно -->
<div id="welcomeTourModal">
    <div style="min-height: 100%; display: flex; align-items: center; justify-content: center; padding: 12px;">
        <div class="tour-card-container">
            
            <!-- Анимированная верхняя линия спектра -->
            <div style="height: 4px; width: 100%; background: linear-gradient(90deg, #6366f1, #a855f7, #ec4899, #38bdf8, #10b981, #6366f1); background-size: 200% 100%; animation: tourShimmerBar 4s linear infinite;"></div>

            <!-- Верхняя шапка окна -->
            <div style="padding: 20px 24px 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, #6366f1, #a855f7); display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.45);">
                        <i class="fas fa-rocket"></i>
                    </div>
                    <div>
                        <div style="font-size: 16px; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
                            <span>AI Business Assistant</span>
                            <span style="font-size: 10px; font-weight: 800; text-transform: uppercase; padding: 2px 8px; border-radius: 20px; background: rgba(168, 85, 247, 0.25); color: #d8b4fe; border: 1px solid rgba(168, 85, 247, 0.4);">Экскурс</span>
                        </div>
                        <div style="font-size: 12px; color: #94a3b8; margin-top: 1px;">
                            Интеллектуальная бизнес-экосистема нового поколения
                        </div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 10px;">
                    <span id="tourStepBadge" style="font-size: 12px; font-weight: 800; color: #cbd5e1; background: rgba(255, 255, 255, 0.06); padding: 5px 12px; border-radius: 20px; border: 1px solid rgba(255, 255, 255, 0.1);">
                        Шаг 1 из 6
                    </span>
                    <button type="button" onclick="closeWelcomeTourModal()" style="width: 34px; height: 34px; border-radius: 10px; background: rgba(255, 255, 255, 0.06); border: 1px solid rgba(255, 255, 255, 0.1); color: #94a3b8; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; transition: all 0.2s;" onmouseover="this.style.color='#fff'; this.style.background='rgba(255,255,255,0.15)';" onmouseout="this.style.color='#94a3b8'; this.style.background='rgba(255,255,255,0.06)';">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>

            <!-- Интерактивная панель шагов / табов -->
            <div style="padding: 12px 24px; background: rgba(0, 0, 0, 0.2); border-bottom: 1px solid rgba(255, 255, 255, 0.05); display: flex; gap: 8px; overflow-x: auto; scrollbar-width: none;">
                <div class="tour-step-pill active" onclick="goToTourSlide(0)" data-step="0">
                    <i class="fas fa-star" style="color: #fbbf24;"></i> О проекте
                </div>
                <div class="tour-step-pill" onclick="goToTourSlide(1)" data-step="1">
                    <i class="fas fa-robot" style="color: #818cf8;"></i> AI-Ассистент
                </div>
                <div class="tour-step-pill" onclick="goToTourSlide(2)" data-step="2">
                    <i class="fas fa-cash-register" style="color: #34d399;"></i> CRM & Касса
                </div>
                <div class="tour-step-pill" onclick="goToTourSlide(3)" data-step="3">
                    <i class="fas fa-phone-volume" style="color: #38bdf8;"></i> Чаты & Звонки
                </div>
                <div class="tour-step-pill" onclick="goToTourSlide(4)" data-step="4">
                    <i class="fas fa-building" style="color: #f472b6;"></i> Бизнес & Документы
                </div>
                <div class="tour-step-pill" onclick="goToTourSlide(5)" data-step="5">
                    <i class="fas fa-shield-alt" style="color: #fb923c;"></i> Быстрый старт
                </div>
            </div>

            <!-- Тело со слайдами -->
            <div style="padding: 24px; min-height: 380px;">

                <!-- Слайд 0: О проекте и миссии -->
                <div class="tour-slide active" id="tourSlide0">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
                        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #fbbf24; background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.35); padding: 4px 10px; border-radius: 8px;">
                            <i class="fas fa-award"></i> Добро пожаловать, <?= $tourUserName ?>!
                        </span>
                        <span style="font-size: 12px; color: #94a3b8;">
                            Версия платформы: <strong>2.5 Release (Desktop App)</strong>
                        </span>
                    </div>

                    <h2 style="font-size: 22px; font-weight: 800; color: #fff; line-height: 1.3; margin-bottom: 10px;">
                        AI Business Assistant — комплексная экосистема для автоматизации вашего бизнеса
                    </h2>
                    
                    <p style="font-size: 14px; color: #cbd5e1; line-height: 1.6; margin-bottom: 18px;">
                        Вам больше не нужно платить за 5–10 разных программ и пытаться их объединить. Платформа совмещает в одном удобном окне работу искусственного интеллекта, CRM для клиентов, электронную онлайн-кассу, внутреннюю защищённую IP-телефонию и генерацию юридических документов.
                    </p>

                    <div class="tour-feature-grid">
                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(99, 102, 241, 0.2); color: #818cf8;">
                                <i class="fas fa-bolt"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Скорость & Экономия</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Рутинные задачи, которые раньше занимали часы (расчет налогов, счета, договоры), теперь решаются за 15 секунд.
                            </div>
                        </div>

                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Единая база данных</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Клиенты, чеки, звонки, сотрудники и касса синхронизированы в реальном времени между всеми отделами.
                            </div>
                        </div>

                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(236, 72, 153, 0.2); color: #f472b6;">
                                <i class="fas fa-desktop"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Desktop Experience</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Полноценное нативное десктопное приложение: мгновенный запуск, чистое окно без рамок браузера и горячие клавиши.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Слайд 1: AI-Ассистент -->
                <div class="tour-slide" id="tourSlide1">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #818cf8; background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.35); padding: 4px 10px; border-radius: 8px;">
                            <i class="fas fa-brain"></i> Нейросетевое ядро
                        </span>
                    </div>

                    <h2 style="font-size: 22px; font-weight: 800; color: #fff; line-height: 1.3; margin-bottom: 10px;">
                        Интеллектуальный бизнес-советник и консультант 24/7
                    </h2>
                    
                    <p style="font-size: 14px; color: #cbd5e1; line-height: 1.6; margin-bottom: 14px;">
                        ИИ обучен тонкостям коммерческой деятельности, налоговому кодексу, маркетингу и юриспруденции. Задавайте вопросы простым языком или прикрепляйте файлы для моментального анализа.
                    </p>

                    <div class="tour-feature-grid">
                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(99, 102, 241, 0.2); color: #818cf8;">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Налоги & СНР 3%</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Мгновенный расчёт налоговых платежей, упрощёнки, калькуляция чистой маржи и проверка окупаемости проектов.
                            </div>
                        </div>

                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24;">
                                <i class="fas fa-handshake"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Продажи & КП</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Формирование продающих коммерческих предложений (КП) за 10 секунд и готовые скрипты отработки возражений покупателей.
                            </div>
                        </div>

                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(168, 85, 247, 0.2); color: #c084fc;">
                                <i class="fas fa-file-search"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Анализ договоров</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Загружайте файлы договоров и актов — ассистент найдет скрытые штрафы, риски и подскажет безопасные формулировки.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Слайд 2: CRM & Касса -->
                <div class="tour-slide" id="tourSlide2">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #34d399; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.35); padding: 4px 10px; border-radius: 8px;">
                            <i class="fas fa-cash-register"></i> Торговля и учет
                        </span>
                    </div>

                    <h2 style="font-size: 22px; font-weight: 800; color: #fff; line-height: 1.3; margin-bottom: 10px;">
                        CRM-система, онлайн-касса (POS) и склад в одном модуле
                    </h2>
                    
                    <p style="font-size: 14px; color: #cbd5e1; line-height: 1.6; margin-bottom: 14px;">
                        Ведите клиентов от первого касания до успешной продажи и автоматической фискализации чека.
                    </p>

                    <div class="tour-feature-grid">
                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                                <i class="fas fa-users"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Умная CRM-база</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Полный учёт клиентов, контактов, статусов воронки продаж и персональная история заказов.
                            </div>
                        </div>

                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(59, 130, 246, 0.2); color: #60a5fa;">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">POS Онлайн-касса</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Мгновенный приём оплат (наличные, безнал, Kaspi/QR), автоматический расчёт сдачи и печать чеков.
                            </div>
                        </div>

                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(245, 158, 11, 0.2); color: #fbbf24;">
                                <i class="fas fa-utensils"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Каталог & Склад</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Рубрикатор товаров и ресторанного меню, учёт складских остатков и удобный поиск со сканера штрихкодов.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Слайд 3: Чаты & Звонки -->
                <div class="tour-slide" id="tourSlide3">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #38bdf8; background: rgba(56, 189, 248, 0.15); border: 1px solid rgba(56, 189, 248, 0.35); padding: 4px 10px; border-radius: 8px;">
                            <i class="fas fa-comments"></i> Коммуникации
                        </span>
                    </div>

                    <h2 style="font-size: 22px; font-weight: 800; color: #fff; line-height: 1.3; margin-bottom: 10px;">
                        Корпоративная телефония, каналы и приватные чаты
                    </h2>
                    
                    <p style="font-size: 14px; color: #cbd5e1; line-height: 1.6; margin-bottom: 14px;">
                        Собственная корпоративная связь защищает коммерческую тайну и ускоряет координацию сотрудников без задержек.
                    </p>

                    <div class="tour-feature-grid">
                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(56, 189, 248, 0.2); color: #38bdf8;">
                                <i class="fas fa-phone-alt"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Аудиозвонки 1-на-1</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Прямые телефонные звонки коллегам в один клик прямо из приложения с кристально чистым звуком WebRTC.
                            </div>
                        </div>

                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(168, 85, 247, 0.2); color: #c084fc;">
                                <i class="fas fa-microphone"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Голосовые заметки</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Отправляйте голосовые сообщения в личной переписке (DM) и прикрепляйте рабочие файлы к сообщениям.
                            </div>
                        </div>

                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(236, 72, 153, 0.2); color: #f472b6;">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Каналы отделов</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Раздельные рабочие комнаты: Общий канал, Чат дирекции, Отдел продаж и круглосуточная техподдержка.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Слайд 4: Бизнес & Документы -->
                <div class="tour-slide" id="tourSlide4">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #f472b6; background: rgba(236, 72, 153, 0.15); border: 1px solid rgba(236, 72, 153, 0.35); padding: 4px 10px; border-radius: 8px;">
                            <i class="fas fa-sitemap"></i> Организационная структура
                        </span>
                    </div>

                    <h2 style="font-size: 22px; font-weight: 800; color: #fff; line-height: 1.3; margin-bottom: 10px;">
                        Управление филиалами, штат компании и документация
                    </h2>
                    
                    <p style="font-size: 14px; color: #cbd5e1; line-height: 1.6; margin-bottom: 14px;">
                        Полноценная корпоративная иерархия с автоматическим формированием кадровых и юридических документов.
                    </p>

                    <div class="tour-feature-grid">
                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(236, 72, 153, 0.2); color: #f472b6;">
                                <i class="fas fa-building"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Филиалы и Организации</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Подача заявок на регистрацию новых юридических лиц и предприятий прямо из личного кабинета.
                            </div>
                        </div>

                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">
                                <i class="fas fa-user-tag"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Штатное расписание</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Назначение Генерального директора, управляющих, менеджеров с разграничением прав на кассу и отчёты.
                            </div>
                        </div>

                        <div class="tour-feature-card">
                            <div class="tour-icon-box" style="background: rgba(99, 102, 241, 0.2); color: #818cf8;">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div style="font-size: 14px; font-weight: 700; color: #fff; margin-bottom: 4px;">Сводная аналитика</div>
                            <div style="font-size: 12px; color: #94a3b8; line-height: 1.45;">
                                Графики динамики выручки, средний чек, количество закрытых задач и автоматическая генерация актов.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Слайд 5: Быстрый старт и безопасность -->
                <div class="tour-slide" id="tourSlide5">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                        <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #fb923c; background: rgba(251, 146, 60, 0.15); border: 1px solid rgba(251, 146, 60, 0.35); padding: 4px 10px; border-radius: 8px;">
                            <i class="fas fa-shield-alt"></i> Защита и готовность
                        </span>
                    </div>

                    <h2 style="font-size: 22px; font-weight: 800; color: #fff; line-height: 1.3; margin-bottom: 10px;">
                        Платформа готова к работе. Ваш бизнес под надёжной защитой
                    </h2>
                    
                    <p style="font-size: 14px; color: #cbd5e1; line-height: 1.6; margin-bottom: 16px;">
                        Мы позаботились о безопасности ваших данных, стабильности работы и максимальном комфорте управления.
                    </p>

                    <div style="background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 16px 20px; margin-bottom: 20px;">
                        <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 12px;">
                            <div style="color: #34d399; font-size: 18px; margin-top: 2px;"><i class="fas fa-check-circle"></i></div>
                            <div>
                                <div style="font-size: 14px; font-weight: 700; color: #fff;">Двухфакторная защита (2FA) с кулдауном 2 часа</div>
                                <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">
                                    Включите 2FA в разделе «Профиль» — вход будет подтверждаться одноразовым кодом на вашу почту без надоедливых повторов.
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 12px;">
                            <div style="color: #60a5fa; font-size: 18px; margin-top: 2px;"><i class="fas fa-headset"></i></div>
                            <div>
                                <div style="font-size: 14px; font-weight: 700; color: #fff;">Круглосуточная поддержка прямо в приложении</div>
                                <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">
                                    Служба заботы доступна из бокового меню и даже на стартовом экране без необходимости входа.
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; align-items: flex-start; gap: 14px;">
                            <div style="color: #c084fc; font-size: 18px; margin-top: 2px;"><i class="fas fa-shield-alt"></i></div>
                            <div>
                                <div style="font-size: 14px; font-weight: 700; color: #fff;">Защита коммерческих данных</div>
                                <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">
                                    Все базы данных, записи звонков и переписка хранятся локально в защищенном контуре вашего предприятия.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="text-align: center;">
                        <button type="button" onclick="finishWelcomeTour()" class="btn btn-primary" style="padding: 13px 34px; font-size: 15px; font-weight: 800; background: linear-gradient(135deg, #10b981, #059669); border: none; border-radius: 12px; box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4); cursor: pointer; display: inline-flex; align-items: center; gap: 10px;">
                            <i class="fas fa-paper-plane"></i> Всё понятно, перейти к работе!
                        </button>
                    </div>
                </div>

            </div>

            <!-- Нижняя панель действий / футер модалки -->
            <div style="padding: 16px 24px; border-top: 1px solid rgba(255, 255, 255, 0.08); background: rgba(0, 0, 0, 0.25); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                
                <!-- Инфо для нового пользователя -->
                <div style="display: inline-flex; align-items: center; gap: 8px; font-size: 12px; color: #94a3b8; user-select: none;">
                    <i class="fas fa-sparkles" style="color: #a855f7;"></i>
                    <span>Вводный тур для нового пользователя</span>
                </div>

                <!-- Точечные индикаторы шагов -->
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span class="tour-dot active" onclick="goToTourSlide(0)"></span>
                    <span class="tour-dot" onclick="goToTourSlide(1)"></span>
                    <span class="tour-dot" onclick="goToTourSlide(2)"></span>
                    <span class="tour-dot" onclick="goToTourSlide(3)"></span>
                    <span class="tour-dot" onclick="goToTourSlide(4)"></span>
                    <span class="tour-dot" onclick="goToTourSlide(5)"></span>
                </div>

                <!-- Кнопки навигации -->
                <div style="display: flex; align-items: center; gap: 10px;">
                    <button type="button" id="tourPrevBtn" onclick="prevTourSlide()" class="btn btn-secondary btn-sm" style="padding: 8px 16px; font-size: 13px; font-weight: 700; opacity: 0.5;" disabled>
                        <i class="fas fa-arrow-left"></i> Назад
                    </button>
                    <button type="button" id="tourNextBtn" onclick="nextTourSlide()" class="btn btn-primary btn-sm" style="padding: 8px 18px; font-size: 13px; font-weight: 800; background: linear-gradient(135deg, #6366f1, #a855f7); border: none; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.35);">
                        <span>Далее</span> <i class="fas fa-arrow-right"></i>
                    </button>
                </div>

            </div>

        </div>
    </div>
</div>

<!-- JS Контроллер Обучающего Окна -->
<script>
var currentTourSlide = 0;
var totalTourSlides = 6;
var tourUserId = <?= $tourUserId ?>;

function openWelcomeTourModal(startSlide) {
    if (typeof startSlide === 'number') {
        currentTourSlide = startSlide;
    }
    var modal = document.getElementById('welcomeTourModal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        renderTourSlide();
    }
}

function closeWelcomeTourModal() {
    var modal = document.getElementById('welcomeTourModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
    try {
        localStorage.setItem('aba_tour_dismissed_' + tourUserId, '1');
    } catch(e) {}
}

function finishWelcomeTour() {
    try {
        localStorage.setItem('aba_tour_dismissed_' + tourUserId, '1');
    } catch(e) {}
    closeWelcomeTourModal();
}

function goToTourSlide(index) {
    if (index >= 0 && index < totalTourSlides) {
        currentTourSlide = index;
        renderTourSlide();
    }
}

function nextTourSlide() {
    if (currentTourSlide < totalTourSlides - 1) {
        currentTourSlide++;
        renderTourSlide();
    } else {
        finishWelcomeTour();
    }
}

function prevTourSlide() {
    if (currentTourSlide > 0) {
        currentTourSlide--;
        renderTourSlide();
    }
}

function renderTourSlide() {
    // Скрываем все слайды
    for (var i = 0; i < totalTourSlides; i++) {
        var s = document.getElementById('tourSlide' + i);
        if (s) s.classList.remove('active');
    }
    // Активируем нужный
    var activeSlide = document.getElementById('tourSlide' + currentTourSlide);
    if (activeSlide) activeSlide.classList.add('active');

    // Обновляем шаги-пилюли
    var pills = document.querySelectorAll('.tour-step-pill');
    pills.forEach(function(pill, idx) {
        if (idx === currentTourSlide) {
            pill.classList.add('active');
        } else {
            pill.classList.remove('active');
        }
    });

    // Обновляем точки
    var dots = document.querySelectorAll('.tour-dot');
    dots.forEach(function(dot, idx) {
        if (idx === currentTourSlide) {
            dot.classList.add('active');
        } else {
            dot.classList.remove('active');
        }
    });

    // Обновляем бейдж "Шаг X из Y"
    var badge = document.getElementById('tourStepBadge');
    if (badge) {
        badge.textContent = 'Шаг ' + (currentTourSlide + 1) + ' из ' + totalTourSlides;
    }

    // Кнопка Назад
    var prevBtn = document.getElementById('tourPrevBtn');
    if (prevBtn) {
        if (currentTourSlide === 0) {
            prevBtn.disabled = true;
            prevBtn.style.opacity = '0.4';
            prevBtn.style.cursor = 'not-allowed';
        } else {
            prevBtn.disabled = false;
            prevBtn.style.opacity = '1';
            prevBtn.style.cursor = 'pointer';
        }
    }

    // Кнопка Далее
    var nextBtn = document.getElementById('tourNextBtn');
    if (nextBtn) {
        if (currentTourSlide === totalTourSlides - 1) {
            nextBtn.innerHTML = '<span>Завершить 🚀</span>';
            nextBtn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
        } else {
            nextBtn.innerHTML = '<span>Далее</span> <i class="fas fa-arrow-right"></i>';
            nextBtn.style.background = 'linear-gradient(135deg, #6366f1, #a855f7)';
        }
    }
}

// Управление с клавиатуры (стрелки влево/вправо, Escape)
document.addEventListener('keydown', function(e) {
    var modal = document.getElementById('welcomeTourModal');
    if (!modal || modal.style.display !== 'block') return;

    if (e.key === 'ArrowRight') {
        nextTourSlide();
    } else if (e.key === 'ArrowLeft') {
        prevTourSlide();
    } else if (e.key === 'Escape') {
        closeWelcomeTourModal();
    }
});

// Автоматический запуск при регистрации: показывается ТОЛЬКО новым аккаунтам и СТРОГО один раз
document.addEventListener('DOMContentLoaded', function() {
    var isNewAccount = <?= $shouldForceTour ? 'true' : 'false' ?>;
    var isMaintBlocking = <?= $isMaintBlocking ? 'true' : 'false' ?>;
    
    if (isMaintBlocking || !isNewAccount) {
        return; // Никакого авто-показа существующим аккаунтам или при обычном входе
    }

    var tourDismissed = false;
    try {
        tourDismissed = (localStorage.getItem('aba_tour_dismissed_' + tourUserId) === '1');
    } catch(e) {}

    if (!tourDismissed) {
        setTimeout(function() {
            openWelcomeTourModal(0);
        }, 350);
    }
});
</script>
