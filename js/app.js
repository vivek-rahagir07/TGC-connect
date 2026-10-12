/**
 * TGC Connect - Pure Vanilla JS Client Library & Animation Engine
 */

// 1. Web Audio API Sound Engine (zero external audio files, pure browser synthesis)
let audioCtx = null;
let soundEnabled = true;

function getAudioContext() {
  if (!audioCtx) {
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (AudioContext) {
      audioCtx = new AudioContext();
    }
  }
  if (audioCtx && audioCtx.state === 'suspended') {
    audioCtx.resume();
  }
  return audioCtx;
}

function playSound(type = 'click') {
  if (!soundEnabled) return;
  try {
    const ctx = getAudioContext();
    if (!ctx) return;

    const now = ctx.currentTime;
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.connect(gain);
    gain.connect(ctx.destination);

    if (type === 'click') {
      osc.type = 'sine';
      osc.frequency.setValueAtTime(600, now);
      osc.frequency.exponentialRampToValueAtTime(300, now + 0.04);
      gain.gain.setValueAtTime(0.08, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.04);
      osc.start(now);
      osc.stop(now + 0.04);
    } else if (type === 'success') {
      // Pleasant dual tone chime
      osc.type = 'triangle';
      osc.frequency.setValueAtTime(523.25, now); // C5
      osc.frequency.setValueAtTime(659.25, now + 0.1); // E5
      osc.frequency.setValueAtTime(783.99, now + 0.2); // G5
      gain.gain.setValueAtTime(0.12, now);
      gain.gain.linearRampToValueAtTime(0.15, now + 0.2);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
      osc.start(now);
      osc.stop(now + 0.5);
    } else if (type === 'error') {
      osc.type = 'sawtooth';
      osc.frequency.setValueAtTime(220, now);
      osc.frequency.linearRampToValueAtTime(140, now + 0.2);
      gain.gain.setValueAtTime(0.15, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.25);
      osc.start(now);
      osc.stop(now + 0.25);
    } else if (type === 'shutter') {
      // Camera shutter snap
      osc.type = 'square';
      osc.frequency.setValueAtTime(800, now);
      osc.frequency.exponentialRampToValueAtTime(120, now + 0.08);
      gain.gain.setValueAtTime(0.18, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.08);
      osc.start(now);
      osc.stop(now + 0.08);
    } else if (type === 'beep') {
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, now);
      gain.gain.setValueAtTime(0.1, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.1);
      osc.start(now);
      osc.stop(now + 0.1);
    }
  } catch (e) {
    // Audio not allowed yet before user interaction
  }
}

// 2. Celebratory Canvas Confetti Burst (Lightweight, pure canvas)
function fireConfetti(durationMs = 2500) {
  let canvas = document.getElementById('confettiCanvas');
  if (!canvas) {
    canvas = document.createElement('canvas');
    canvas.id = 'confettiCanvas';
    document.body.appendChild(canvas);
  }

  canvas.width = window.innerWidth;
  canvas.height = window.innerHeight;
  const ctx = canvas.getContext('2d');

  const particles = [];
  const colors = ['#4f46e5', '#06b6d4', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#3b82f6'];

  for (let i = 0; i < 90; i++) {
    particles.push({
      x: canvas.width / 2,
      y: canvas.height / 2 + 100,
      vx: (Math.random() - 0.5) * 16,
      vy: (Math.random() - 0.8) * 18,
      size: Math.random() * 8 + 4,
      color: colors[Math.floor(Math.random() * colors.length)],
      rotation: Math.random() * 360,
      rotSpeed: (Math.random() - 0.5) * 10,
      gravity: 0.35,
      opacity: 1
    });
  }

  const startTime = Date.now();

  function animate() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const elapsed = Date.now() - startTime;

    let alive = false;
    for (let p of particles) {
      p.x += p.vx;
      p.y += p.vy;
      p.vy += p.gravity;
      p.rotation += p.rotSpeed;
      p.opacity = Math.max(0, 1 - (elapsed / durationMs));

      if (p.opacity > 0 && p.y < canvas.height + 50) {
        alive = true;
        ctx.save();
        ctx.globalAlpha = p.opacity;
        ctx.translate(p.x, p.y);
        ctx.rotate((p.rotation * Math.PI) / 180);
        ctx.fillStyle = p.color;
        ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 1.4);
        ctx.restore();
      }
    }

    if (alive && elapsed < durationMs) {
      requestAnimationFrame(animate);
    } else {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
  }

  requestAnimationFrame(animate);
}

// 3. Animated Number Counter (0 -> Target)
function animateValue(element, start, end, duration = 800) {
  if (!element) return;
  const startTimestamp = performance.now();
  const step = (now) => {
    const progress = Math.min((now - startTimestamp) / duration, 1);
    // Ease out quad
    const easeProgress = 1 - (1 - progress) * (1 - progress);
    const current = Math.floor(easeProgress * (end - start) + start);
    element.textContent = current.toLocaleString();
    if (progress < 1) {
      requestAnimationFrame(step);
    } else {
      element.textContent = end.toLocaleString();
    }
  };
  requestAnimationFrame(step);
}

// 4. Device Fingerprint (Anti-Proxy Helper)
function getDeviceFingerprint() {
  const nav = window.navigator;
  const screen = window.screen;
  const raw = [
    nav.userAgent,
    nav.language,
    screen.width + 'x' + screen.height,
    screen.colorDepth,
    new Date().getTimezoneOffset()
  ].join('|');

  // Simple string hash
  let hash = 0;
  for (let i = 0; i < raw.length; i++) {
    hash = ((hash << 5) - hash) + raw.charCodeAt(i);
    hash |= 0;
  }
  return 'fp-' + Math.abs(hash).toString(16);
}

// 5. Toast Notifications
function showToast(message, type = 'info') {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
  }

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;

  let icon = 'ℹ️';
  if (type === 'success') {
    icon = '✅';
    playSound('success');
  } else if (type === 'error') {
    icon = '❌';
    playSound('error');
  } else if (type === 'warning') {
    icon = '⚠️';
    playSound('beep');
  } else {
    playSound('click');
  }

  toast.innerHTML = `<span>${icon}</span> <span>${message}</span>`;
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(100%)';
    toast.style.transition = 'all 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 4200);
}

// 6. Generic API Client
async function apiRequest(endpoint, method = 'GET', data = null) {
  const options = {
    method: method,
    headers: {
      'Accept': 'application/json',
    },
  };

  if (data) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(data);
  }

  try {
    const res = await fetch(`api/${endpoint}`, options);
    const json = await res.json();
    return { ok: res.ok, status: res.status, data: json };
  } catch (err) {
    console.error('API Error:', err);
    return { ok: false, status: 500, data: { message: 'Failed to connect to backend server.' } };
  }
}

// 7. Modal Control with Light-Dismiss
function openModal(id) {
  const m = document.getElementById(id);
  if (m) {
    m.classList.add('active');
    playSound('click');
  }
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (m) {
    m.classList.remove('active');
  }
}

// Global light dismiss & ESC key handler for modals
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
  }
});

// Track mousedown target so scroll-then-release doesn't dismiss
let _modalDismissTarget = null;
document.addEventListener('mousedown', (e) => {
  _modalDismissTarget = e.target;
});
document.addEventListener('mouseup', (e) => {
  // Only dismiss if BOTH mousedown and mouseup were on the overlay itself
  if (_modalDismissTarget && _modalDismissTarget === e.target &&
      e.target.classList && e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('active');
  }
  _modalDismissTarget = null;
});
// Also handle touch: use touchstart/touchend for mobile
let _modalTouchTarget = null;
document.addEventListener('touchstart', (e) => {
  _modalTouchTarget = e.target;
}, { passive: true });
document.addEventListener('touchend', (e) => {
  if (_modalTouchTarget && _modalTouchTarget === e.target &&
      e.target.classList && e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('active');
  }
  _modalTouchTarget = null;
}, { passive: true });

// 8. Workplace Digital Clock
function initDigitalClock(elementId) {
  const el = document.getElementById(elementId);
  if (!el) return;

  function tick() {
    const now = new Date();
    const timeStr = now.toLocaleTimeString([], { hour12: false });
    const dateStr = now.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
    el.innerHTML = `<span style="font-weight: 800; letter-spacing: 0.5px;">${timeStr}</span> &bull; <span style="opacity: 0.85;">${dateStr}</span>`;
  }
  tick();
  setInterval(tick, 1000);
}

// 9. Auth State Navigation Bar Sync
async function handleLogout() {
  playSound('click');
  await apiRequest('auth.php?action=logout', 'POST');
  window.location.href = 'login.html';
}

async function checkAuthNav() {
  const res = await apiRequest('auth.php?action=me');
  const navUserArea = document.getElementById('navUserArea');
  const navMenu = document.getElementById('navMenu');

  if (res.ok && res.data.authenticated) {
    const u = res.data.user;
    if (navMenu) {
      if (u.role === 'admin') {
        navMenu.innerHTML = `
          <a href="admin.html" class="nav-link"><i data-lucide="layout-dashboard" style="width: 18px;"></i> Admin Console</a>
          <a href="mark_attendance.html" class="nav-link"><i data-lucide="map-pin" style="width: 18px;"></i> Mark Attendance</a>
        `;
      } else {
        navMenu.innerHTML = `
          <a href="portal.html" class="nav-link"><i data-lucide="user" style="width: 18px;"></i> Employee Portal</a>
          <a href="mark_attendance.html" class="nav-link"><i data-lucide="map-pin" style="width: 18px;"></i> Mark Attendance</a>
        `;
      }
    }

    window.currentUser = u;
    if (navUserArea) {
      navUserArea.innerHTML = `
        <div style="display: flex; align-items: center; gap: 0.65rem;">
          <button type="button" class="btn-pwa-nav-install" id="pwaNavInstallBtn" onclick="triggerPwaInstall()" title="Install TGC Connect on your phone or desktop">
            <span class="install-icon">📲</span>
            <span>Install App</span>
          </button>

          <div class="user-nav-dropdown" id="userNavDropdownWrap">
            <div class="user-nav-trigger" id="userNavTriggerBtn" onclick="toggleUserDropdown(event)">
              <div class="avatar-sm" style="width: 34px; height: 34px; min-width: 34px;">
                ${u.photo_url ? `<img src="${u.photo_url}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">` : escapeHtml(u.name.charAt(0).toUpperCase())}
              </div>
              <div class="user-nav-text-wrap" style="line-height: 1.2; text-align: left;">
                <div style="font-size: 0.82rem; font-weight: 800; max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-main);">${escapeHtml(u.name)}</div>
                <div style="font-size: 0.68rem; color: var(--text-muted); text-transform: capitalize; font-weight: 600;">${escapeHtml(u.role)}</div>
              </div>
              <i data-lucide="chevron-down" class="user-nav-chevron" style="width: 14px; height: 14px; color: var(--text-muted); margin-left: 2px;"></i>
            </div>

            <div class="user-nav-menu" id="userNavDropdownMenu" style="display: none;">
              <div class="user-nav-header">
                <div style="font-weight: 800; font-size: 0.9rem; color: var(--text-main);">${escapeHtml(u.name)}</div>
                <div style="font-size: 0.74rem; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px;">${escapeHtml(u.email)}</div>
                <div style="display: flex; gap: 0.35rem; margin-top: 0.5rem; flex-wrap: wrap;">
                  <span class="badge badge-primary" style="font-size: 0.65rem; padding: 2px 7px; border-radius: 9999px;">${escapeHtml(u.role.toUpperCase())}</span>
                  <span class="badge badge-secondary" style="font-size: 0.65rem; padding: 2px 7px; border-radius: 9999px;">${escapeHtml(u.department || 'Operations')}</span>
                </div>
              </div>

              <button type="button" class="user-nav-item" onclick="triggerPwaInstall()">
                <span style="font-size: 1rem; width: 15px; display: inline-flex; align-items: center; justify-content: center;">📲</span> Install App
              </button>

              <button type="button" class="user-nav-item" onclick="openGlobalProfileModal()">
                <i data-lucide="user" style="width: 15px; color: var(--primary);"></i> View Profile
              </button>

              <button type="button" class="user-nav-item" onclick="openGlobalResetPassModal()">
                <i data-lucide="key-round" style="width: 15px; color: #f59e0b;"></i> Reset Password
              </button>

              <div class="user-nav-divider"></div>

              <button type="button" class="user-nav-item danger" onclick="handleLogout()">
                <i data-lucide="log-out" style="width: 15px;"></i> Sign Out
              </button>
            </div>
          </div>
        </div>
      `;
    }
  } else {
    if (navMenu) {
      navMenu.innerHTML = `
        <a href="index.html" class="nav-link">Home</a>
        <a href="login.html" class="nav-link">Sign In</a>
        <a href="register.html" class="nav-link">Join Team</a>
      `;
    }
    if (navUserArea) {
      navUserArea.innerHTML = `
        <div style="display: flex; align-items: center; gap: 0.5rem;">
          <button type="button" class="btn-pwa-nav-install" id="pwaNavInstallBtn" onclick="triggerPwaInstall()" title="Install TGC Connect on your phone or desktop">
            <span class="install-icon">📲</span>
            <span>Install App</span>
          </button>
          <a href="login.html" class="btn btn-secondary btn-sm">Sign In</a>
          <a href="register.html" class="btn btn-primary btn-sm">Onboard Employee</a>
        </div>
      `;
    }
  }

  // Initialize Freud-inspired mobile navigation drawer
  setupMobileDrawer(window.currentUser);

  if (window.lucide) {
    lucide.createIcons();
  }
}

// ==========================================================================
// Freud-Inspired Mobile Navigation Drawer & Sheet System
// ==========================================================================

function openMobileDrawer() {
  const backdrop = document.getElementById('tgcMobileDrawerBackdrop');
  if (backdrop) {
    backdrop.classList.add('active');
    document.body.style.overflow = 'hidden';
    if (window.lucide) lucide.createIcons();
  }
}

function closeMobileDrawer() {
  const backdrop = document.getElementById('tgcMobileDrawerBackdrop');
  if (backdrop) {
    backdrop.classList.remove('active');
    document.body.style.overflow = '';
  }
}

function handleMobileNav(page, tab) {
  closeMobileDrawer();
  const currentPath = window.location.pathname.split('/').pop() || 'index.html';
  if (currentPath === page) {
    if (page === 'portal.html' && typeof switchTab === 'function' && tab) {
      switchTab(tab);
      return;
    }
    if (page === 'admin.html' && typeof showAdminTab === 'function' && tab) {
      showAdminTab(tab);
      return;
    }
  }
  if (tab) {
    window.location.href = `${page}?tab=${tab}`;
  } else {
    window.location.href = page;
  }
}

function setupMobileDrawer(user) {
  let toggleBtn = document.getElementById('mobileDrawerToggleBtn');
  if (!toggleBtn) {
    const navContainer = document.querySelector('.nav-container');
    if (navContainer) {
      toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.id = 'mobileDrawerToggleBtn';
      toggleBtn.className = 'mobile-drawer-toggle';
      toggleBtn.setAttribute('aria-label', 'Open Mobile Navigation');
      toggleBtn.onclick = openMobileDrawer;
      toggleBtn.innerHTML = '<i data-lucide="menu" style="width: 22px; height: 22px;"></i>';
      navContainer.appendChild(toggleBtn);
    }
  }

  let backdrop = document.getElementById('tgcMobileDrawerBackdrop');
  if (!backdrop) {
    backdrop = document.createElement('div');
    backdrop.id = 'tgcMobileDrawerBackdrop';
    backdrop.className = 'mobile-drawer-backdrop';
    backdrop.onclick = (e) => {
      if (e.target === backdrop) closeMobileDrawer();
    };
    document.body.appendChild(backdrop);
  }

  const u = user || window.currentUser;
  const isAuth = !!u;
  const isAdmin = isAuth && u.role === 'admin';
  const userName = isAuth ? escapeHtml(u.name) : 'Guest User';
  const userSubtitle = isAuth 
    ? (escapeHtml(u.department || 'Getting Roots Coaching & Training') + ' &bull; ' + (isAdmin ? 'Admin Console' : 'Active Employee')) 
    : 'Welcome &bull; Shakarpur Campus';
  const avatarHtml = isAuth && u.photo_url
    ? `<img src="${u.photo_url}" alt="${userName}">`
    : (isAuth ? userName.charAt(0).toUpperCase() : '<i data-lucide="user" style="width: 28px; height: 28px;"></i>');

  let navItemsHtml = '';
  let footerPillsHtml = '';

  if (isAdmin) {
    navItemsHtml = `
      <div class="drawer-section-title">Administration</div>
      <div class="drawer-nav-list">
        <button type="button" class="drawer-nav-item active" onclick="handleMobileNav('admin.html', 'approvals')">
          <i data-lucide="shield-check"></i> Approvals & Leave Requests
        </button>
        <button type="button" class="drawer-nav-item" onclick="handleMobileNav('admin.html', 'employees')">
          <i data-lucide="users"></i> Staff & Employee Directory
        </button>
        <button type="button" class="drawer-nav-item" onclick="handleMobileNav('admin.html', 'attendance')">
          <i data-lucide="clock"></i> Real-Time Attendance Tracking
        </button>
        <button type="button" class="drawer-nav-item" onclick="handleMobileNav('admin.html', 'payroll')">
          <i data-lucide="calculator"></i> Automated Payroll Engine
        </button>
        <button type="button" class="drawer-nav-item" onclick="handleMobileNav('admin.html', 'qr')">
          <i data-lucide="qr-code"></i> Dynamic QR & GPS Links
        </button>
        <button type="button" class="drawer-nav-item" onclick="handleMobileNav('admin.html', 'rosters')">
          <i data-lucide="calendar-days"></i> Shift Schedules & Rosters
        </button>
        <button type="button" class="drawer-nav-item" onclick="handleMobileNav('admin.html', 'inventory')">
          <i data-lucide="package"></i> Office Asset Inventory
        </button>
      </div>

      <div class="drawer-section-title">Account & Security</div>
      <div class="drawer-nav-list">
        <button type="button" class="drawer-nav-item" onclick="closeMobileDrawer();triggerPwaInstall();" style="color: #4f46e5; font-weight: 800;">
          <i data-lucide="download" style="color: #4f46e5;"></i> Install TGC Connect App
        </button>
        <button type="button" class="drawer-nav-item" onclick="closeMobileDrawer();openGlobalProfileModal();">
          <i data-lucide="user"></i> Admin Profile & Credentials
        </button>
        <button type="button" class="drawer-nav-item" onclick="closeMobileDrawer();openGlobalResetPassModal();">
          <i data-lucide="key-round"></i> Reset Password
        </button>
        <button type="button" class="drawer-nav-item danger" onclick="closeMobileDrawer();handleLogout();">
          <i data-lucide="log-out"></i> Sign Out
        </button>
      </div>
    `;
    footerPillsHtml = `
      <button type="button" class="drawer-pill-primary" onclick="handleMobileNav('admin.html', 'payroll')">
        <i data-lucide="calculator" style="width: 15px;"></i> Run Payroll
      </button>
      <button type="button" class="drawer-pill-secondary" onclick="handleMobileNav('mark_attendance.html')">
        <i data-lucide="map-pin" style="width: 15px;"></i> Punch Live
      </button>
    `;
  } else if (isAuth) {
    navItemsHtml = `
      <div class="drawer-section-title">General</div>
      <div class="drawer-nav-list">
        <button type="button" class="drawer-nav-item active" onclick="handleMobileNav('portal.html', 'today')">
          <i data-lucide="clock"></i> Today's Attendance Punch
        </button>
        <button type="button" class="drawer-nav-item" onclick="handleMobileNav('portal.html', 'attendance')">
          <i data-lucide="history"></i> Attendance Log & History
        </button>
        <button type="button" class="drawer-nav-item" onclick="handleMobileNav('portal.html', 'leaves')">
          <i data-lucide="calendar-off"></i> Leave Quotas & Apply
        </button>
        <button type="button" class="drawer-nav-item" onclick="handleMobileNav('portal.html', 'salary')">
          <i data-lucide="receipt"></i> Official Salary Slips
        </button>
        <button type="button" class="drawer-nav-item" onclick="handleMobileNav('portal.html', 'calendar')">
          <i data-lucide="calendar"></i> Academic Calendar & Holidays
        </button>
        <button type="button" class="drawer-nav-item" onclick="handleMobileNav('mark_attendance.html')">
          <i data-lucide="map-pin"></i> GPS & Remote Punch
        </button>
      </div>

      <div class="drawer-section-title">Profile & Preferences</div>
      <div class="drawer-nav-list">
        <button type="button" class="drawer-nav-item" onclick="closeMobileDrawer();triggerPwaInstall();" style="color: #4f46e5; font-weight: 800;">
          <i data-lucide="download" style="color: #4f46e5;"></i> Install TGC Connect App
        </button>
        <button type="button" class="drawer-nav-item" onclick="closeMobileDrawer();if(typeof openProfileEditModal === 'function')openProfileEditModal();else openGlobalProfileModal();">
          <i data-lucide="user"></i> Employee Profile & Edit
        </button>
        <button type="button" class="drawer-nav-item" onclick="closeMobileDrawer();openGlobalResetPassModal();">
          <i data-lucide="key-round"></i> Reset Password
        </button>
        <button type="button" class="drawer-nav-item danger" onclick="closeMobileDrawer();handleLogout();">
          <i data-lucide="log-out"></i> Sign Out
        </button>
      </div>
    `;
    footerPillsHtml = `
      <button type="button" class="drawer-pill-primary" onclick="handleMobileNav('mark_attendance.html')">
        <i data-lucide="zap" style="width: 15px;"></i> Quick Punch
      </button>
      <button type="button" class="drawer-pill-secondary" onclick="handleMobileNav('portal.html', 'calendar')">
        <i data-lucide="calendar" style="width: 15px;"></i> Calendar
      </button>
    `;
  } else {
    navItemsHtml = `
      <div class="drawer-section-title">Get Started</div>
      <div class="drawer-nav-list">
        <button type="button" class="drawer-nav-item" onclick="closeMobileDrawer();triggerPwaInstall();" style="color: #4f46e5; font-weight: 800;">
          <i data-lucide="download" style="color: #4f46e5;"></i> Install App on Phone
        </button>
        <a href="login.html" class="drawer-nav-item active">
          <i data-lucide="log-in"></i> Sign In to Account
        </a>
        <a href="register.html" class="drawer-nav-item">
          <i data-lucide="user-plus"></i> Employee Self-Onboard
        </a>
        <a href="mark_attendance.html" class="drawer-nav-item">
          <i data-lucide="map-pin"></i> Mark Attendance Punch
        </a>
      </div>
    `;
    footerPillsHtml = `
      <a href="login.html" class="drawer-pill-primary">
        <i data-lucide="log-in" style="width: 15px;"></i> Sign In
      </a>
      <a href="register.html" class="drawer-pill-secondary">
        <i data-lucide="user-plus" style="width: 15px;"></i> Join Team
      </a>
    `;
  }

  backdrop.innerHTML = `
    <div class="mobile-drawer" onclick="event.stopPropagation()">
      <div class="mobile-drawer-header">
        <div class="drawer-header-bar">
          <div class="drawer-brand">
            <div class="drawer-brand-icon"><i data-lucide="sparkles" style="width: 13px; height: 13px;"></i></div>
            <span>TGC Connect</span>
          </div>
          <button type="button" class="drawer-close-btn" onclick="closeMobileDrawer()" aria-label="Close menu">
            <i data-lucide="x" style="width: 18px; height: 18px;"></i>
          </button>
        </div>

        <div class="drawer-avatar-wrap">
          ${avatarHtml}
        </div>
        <div class="drawer-user-name">${userName}</div>
        <div class="drawer-user-motto">${userSubtitle}</div>
      </div>

      <div class="mobile-drawer-body">
        ${navItemsHtml}
      </div>

      <div class="mobile-drawer-footer">
        ${footerPillsHtml}
      </div>
    </div>
  `;

  if (window.lucide) lucide.createIcons();
}

// Global gestures & keyboard controls for mobile drawer
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeMobileDrawer();
});

let _drawerTouchStartX = 0;
document.addEventListener('touchstart', (e) => {
  if (e.touches && e.touches[0]) {
    _drawerTouchStartX = e.touches[0].clientX;
  }
}, { passive: true });

document.addEventListener('touchend', (e) => {
  if (e.changedTouches && e.changedTouches[0]) {
    const diffX = e.changedTouches[0].clientX - _drawerTouchStartX;
    if (diffX > 80) { // swiped right to close
      closeMobileDrawer();
    }
  }
}, { passive: true });

// 10. Webcam Live Streaming & Snapshot Capture
let mediaStream = null;

async function initWebcam(videoElementId) {
  const video = document.getElementById(videoElementId);
  if (!video) return false;

  try {
    mediaStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
      audio: false
    });
    video.srcObject = mediaStream;
    await video.play();
    return true;
  } catch (err) {
    console.warn('Webcam live stream note:', err);
    return false;
  }
}

function stopWebcam() {
  if (mediaStream) {
    mediaStream.getTracks().forEach(track => track.stop());
    mediaStream = null;
  }
}

function takeSnapshot(videoElementId, canvasElementId) {
  const video = document.getElementById(videoElementId);
  const canvas = document.getElementById(canvasElementId);
  if (!video || !canvas) return null;

  canvas.width = video.videoWidth || 640;
  canvas.height = video.videoHeight || 480;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
  playSound('shutter');
  return canvas.toDataURL('image/jpeg', 0.88);
}

// 11. String HTML escaping utility
function escapeHtml(text) {
  if (text === null || text === undefined) return '';
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// 12. Company display short name helper for UI tables and compact badges
function getCompanyShortName(fullName) {
  if (!fullName) return 'Getting Roots';
  if (fullName.includes('Getting Roots')) return 'Getting Roots';
  if (fullName.includes('Torpedo Learning')) return 'Torpedo Learning';
  if (fullName.includes('Torpedo Lifestyle')) return 'Torpedo Lifestyle';
  if (fullName.includes('Project Help Global')) return 'Project Help Global';
  return fullName;
}




// User Dropdown Controller & Global Modals (View Profile, Reset Password)
function toggleUserDropdown(event) {
  if (event) event.stopPropagation();
  const menu = document.getElementById('userNavDropdownMenu');
  const trigger = document.getElementById('userNavTriggerBtn');
  if (!menu) return;

  const isShown = menu.classList.contains('show') || menu.style.display === 'flex';
  if (isShown) {
    menu.classList.remove('show');
    menu.style.display = 'none';
    if (trigger) trigger.classList.remove('active');
  } else {
    menu.classList.add('show');
    menu.style.display = 'flex';
    if (trigger) trigger.classList.add('active');
  }
}

// Global click-outside listener for user menu
document.addEventListener('click', (e) => {
  const wrap = document.getElementById('userNavDropdownWrap');
  const menu = document.getElementById('userNavDropdownMenu');
  const trigger = document.getElementById('userNavTriggerBtn');
  if (menu && (menu.classList.contains('show') || menu.style.display === 'flex')) {
    if (!wrap || !wrap.contains(e.target)) {
      menu.classList.remove('show');
      menu.style.display = 'none';
      if (trigger) trigger.classList.remove('active');
    }
  }
});

// View Profile Global Modal
function openGlobalProfileModal() {
  const menu = document.getElementById('userNavDropdownMenu');
  const trigger = document.getElementById('userNavTriggerBtn');
  if (menu) {
    menu.classList.remove('show');
    menu.style.display = 'none';
  }
  if (trigger) trigger.classList.remove('active');

  let modal = document.getElementById('globalProfileModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'globalProfileModal';
    modal.className = 'modal-overlay';
    document.body.appendChild(modal);
  }

  const u = window.currentUser || {};
  const initial = u.name ? u.name.charAt(0).toUpperCase() : 'U';

  modal.innerHTML = `
    <div class="modal-content" style="max-width: 520px;">
      <div class="modal-header">
        <div style="display: flex; align-items: center; gap: 0.6rem;">
          <div style="width: 32px; height: 32px; border-radius: 50%; background: #e0e7ff; color: var(--primary); display: flex; align-items: center; justify-content: center;">
            <i data-lucide="user-check" style="width: 17px;"></i>
          </div>
          <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0;">Employee Profile</h3>
        </div>
        <button class="close-btn" onclick="closeGlobalModal('globalProfileModal')">&times;</button>
      </div>
      <div class="modal-body" style="padding: 1.25rem;">
        <div style="display: flex; align-items: center; gap: 1rem; background: var(--bg-main); padding: 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 1.2rem;">
          <div style="width: 54px; height: 54px; border-radius: 50%; background: #4f46e5; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; font-weight: 800; overflow: hidden; flex-shrink: 0;">
            ${u.photo_url ? `<img src="${u.photo_url}" style="width: 100%; height: 100%; object-fit: cover;">` : initial}
          </div>
          <div>
            <div style="font-size: 1.1rem; font-weight: 800; color: var(--text-main);">${u.name || '-'}</div>
            <div style="font-size: 0.8rem; color: var(--text-muted);">${u.email || '-'}</div>
            <div style="display: flex; gap: 0.4rem; margin-top: 0.35rem;">
              <span class="badge badge-primary" style="font-size: 0.68rem;">${(u.role || 'employee').toUpperCase()}</span>
              <span class="badge badge-success" style="font-size: 0.68rem;">${u.status || 'Active'}</span>
            </div>
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.85rem; font-size: 0.86rem;">
          <div style="background: #fff; padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Company</div>
            <div style="font-weight: 700; color: var(--text-main); margin-top: 2px;">${u.company || 'Getting Roots Coaching & Training'}</div>
          </div>
          <div style="background: #fff; padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Department</div>
            <div style="font-weight: 700; color: var(--text-main); margin-top: 2px;">${u.department || 'Operations'}</div>
          </div>
          <div style="background: #fff; padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Job Profile / Role</div>
            <div style="font-weight: 700; color: var(--text-main); margin-top: 2px;">${u.job_profile || 'Team Member'}</div>
          </div>
          <div style="background: #fff; padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Phone</div>
            <div style="font-weight: 700; color: var(--text-main); margin-top: 2px;">${u.phone || '-'}</div>
          </div>
          <div style="background: #fff; padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Date of Joining</div>
            <div style="font-weight: 700; color: var(--text-main); margin-top: 2px;">${u.date_of_joining || '-'}</div>
          </div>
          <div style="background: #fff; padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Date of Birth</div>
            <div style="font-weight: 700; color: var(--text-main); margin-top: 2px;">${u.dob || '-'}</div>
          </div>
        </div>

        ${u.address ? `
          <div style="background: #fff; padding: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-top: 0.85rem; font-size: 0.85rem;">
            <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Workplace / Residential Address</div>
            <div style="font-weight: 600; color: var(--text-main); margin-top: 2px;">${u.address}</div>
          </div>
        ` : ''}
      </div>
      <div class="modal-footer" style="padding: 0.85rem 1.25rem; background: #fafafa; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <button type="button" class="btn btn-primary btn-sm" onclick="closeGlobalModal('globalProfileModal'); if (window.switchTab) { switchTab('calendar'); } else { window.location.href = 'portal.html'; }">
          <i data-lucide="calendar" style="width: 14px; margin-right: 4px;"></i> Academic Calendar
        </button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="closeGlobalModal('globalProfileModal')">Close</button>
      </div>
    </div>
  `;

  modal.classList.add('active');
  if (window.lucide) lucide.createIcons();
}

// Reset Password Global Modal
function openGlobalResetPassModal() {
  const menu = document.getElementById('userNavDropdownMenu');
  const trigger = document.getElementById('userNavTriggerBtn');
  if (menu) {
    menu.classList.remove('show');
    menu.style.display = 'none';
  }
  if (trigger) trigger.classList.remove('active');

  let modal = document.getElementById('globalResetPassModal');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'globalResetPassModal';
    modal.className = 'modal-overlay';
    document.body.appendChild(modal);
  }

  modal.innerHTML = `
    <div class="modal-content" style="max-width: 440px;">
      <div class="modal-header">
        <div style="display: flex; align-items: center; gap: 0.6rem;">
          <div style="width: 32px; height: 32px; border-radius: 50%; background: #fef3c7; color: #b45309; display: flex; align-items: center; justify-content: center;">
            <i data-lucide="key-round" style="width: 17px;"></i>
          </div>
          <h3 style="font-size: 1.15rem; font-weight: 800; margin: 0;">Reset / Change Password</h3>
        </div>
        <button class="close-btn" onclick="closeGlobalModal('globalResetPassModal')">&times;</button>
      </div>
      <form onsubmit="handleGlobalChangePassword(event)">
        <div class="modal-body" style="padding: 1.25rem; display: flex; flex-direction: column; gap: 1rem;">
          <div class="form-group">
            <label class="form-label">Current Password *</label>
            <input type="password" id="globalCurrentPass" class="form-control" placeholder="Enter your current password" required>
          </div>

          <div class="form-group">
            <label class="form-label">New Password *</label>
            <input type="password" id="globalNewPass" class="form-control" placeholder="At least 6 characters" minlength="6" required>
          </div>

          <div class="form-group">
            <label class="form-label">Confirm New Password *</label>
            <input type="password" id="globalConfirmPass" class="form-control" placeholder="Confirm your new password" minlength="6" required>
          </div>

          <div id="globalPassErrorMsg" style="display: none; padding: 0.6rem; border-radius: var(--radius-sm); font-size: 0.82rem; background: var(--danger-bg); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2);"></div>
        </div>
        <div class="modal-footer" style="padding: 0.85rem 1.25rem; background: #fafafa; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
          <button type="button" class="btn btn-secondary btn-sm" onclick="closeGlobalModal('globalResetPassModal')">Cancel</button>
          <button type="submit" id="globalPassSubmitBtn" class="btn btn-primary btn-sm">Update Password</button>
        </div>
      </form>
    </div>
  `;

  modal.classList.add('active');
  if (window.lucide) lucide.createIcons();
}

async function handleGlobalChangePassword(e) {
  e.preventDefault();
  const currentPass = document.getElementById('globalCurrentPass').value;
  const newPass = document.getElementById('globalNewPass').value;
  const confirmPass = document.getElementById('globalConfirmPass').value;
  const errorBox = document.getElementById('globalPassErrorMsg');
  const btn = document.getElementById('globalPassSubmitBtn');

  if (newPass !== confirmPass) {
    errorBox.textContent = 'New passwords do not match. Please re-enter.';
    errorBox.style.display = 'block';
    return;
  }

  errorBox.style.display = 'none';
  btn.disabled = true;
  btn.textContent = 'Updating...';

  const res = await apiRequest('auth.php?action=change_password', 'POST', {
    current_password: currentPass,
    new_password: newPass,
    confirm_password: confirmPass
  });

  btn.disabled = false;
  btn.textContent = 'Update Password';

  if (res.ok && res.data.success) {
    playSound('success');
    showToast(res.data.message || 'Password changed successfully!', 'success');
    closeGlobalModal('globalResetPassModal');
  } else {
    playSound('error');
    errorBox.textContent = res.data.message || 'Could not update password. Please verify current password.';
    errorBox.style.display = 'block';
  }
}

function closeGlobalModal(id) {
  const modal = document.getElementById(id);
  if (modal) modal.classList.remove('active');
}

// ==========================================================================
// 10. Shimmering Skeleton Renderers
// ==========================================================================
function renderTableSkeleton(tbody, rows = 5, cols = 6) {
  if (!tbody) return;
  const colWidths = ['40%', '75%', '55%', '85%', '50%', '30%', '65%', '45%'];
  let html = '';
  for (let r = 0; r < rows; r++) {
    html += '<tr class="skeleton-row">';
    for (let c = 0; c < cols; c++) {
      const w = colWidths[(r + c) % colWidths.length];
      if (c === 0 && cols > 4) {
        // First column often has avatar + name in directory
        html += `<td>
          <div style="display: flex; align-items: center; gap: 0.75rem;">
            <div class="skeleton skeleton-avatar"></div>
            <div style="flex: 1;">
              <div class="skeleton skeleton-text" style="width: ${w};"></div>
              <div class="skeleton skeleton-text" style="width: 45%; height: 10px; margin-bottom: 0;"></div>
            </div>
          </div>
        </td>`;
      } else if (c === cols - 1) {
        // Last column is often actions
        html += `<td><div class="skeleton skeleton-btn" style="width: 70px; height: 28px;"></div></td>`;
      } else {
        html += `<td><div class="skeleton skeleton-text" style="width: ${w};"></div></td>`;
      }
    }
    html += '</tr>';
  }
  tbody.innerHTML = html;
}

function renderCardsSkeleton(container, count = 4) {
  if (!container) return;
  let html = '';
  for (let i = 0; i < count; i++) {
    html += `
      <div class="skeleton-card">
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <div class="skeleton skeleton-badge"></div>
          <div class="skeleton skeleton-avatar" style="width: 28px; height: 28px;"></div>
        </div>
        <div class="skeleton skeleton-title" style="width: 60%; margin-top: 0.5rem;"></div>
        <div class="skeleton skeleton-text" style="width: 80%;"></div>
        <div class="skeleton skeleton-text" style="width: 40%; margin-bottom: 0;"></div>
      </div>
    `;
  }
  container.innerHTML = html;
}

// ==========================================================================
// 11. Image Processing & Auto-Compression (Canvas Square Cropper < 200KB)
// ==========================================================================
function processImageUpload(file, options = {}) {
  return new Promise((resolve, reject) => {
    if (!file) {
      reject(new Error('No file selected.'));
      return;
    }

    const isImage = (file.type && file.type.startsWith('image/')) ||
                    /\.(jpe?g|png|webp|gif|bmp|heic|heif)$/i.test(file.name || '');
    if (!isImage) {
      reject(new Error('Please select a valid image file (JPG, PNG, or WebP).'));
      return;
    }

    const maxDim = options.maxDimension || 500;
    const quality = options.quality || 0.82;
    const reader = new FileReader();

    reader.onerror = () => reject(new Error('Failed to read image from device storage.'));
    reader.onload = (e) => {
      const img = new Image();
      img.onerror = () => reject(new Error('Unable to parse image data. Please select a standard JPG or PNG photo.'));
      img.onload = () => {
        try {
          const canvas = document.createElement('canvas');
          canvas.width = maxDim;
          canvas.height = maxDim;
          const ctx = canvas.getContext('2d');

          // Center-crop 1:1 aspect ratio like passport photo
          const size = Math.min(img.width, img.height);
          const startX = (img.width - size) / 2;
          const startY = (img.height - size) / 2;

          // Smooth rendering
          ctx.imageSmoothingEnabled = true;
          ctx.imageSmoothingQuality = 'high';

          // Draw cropped & resized square
          ctx.drawImage(img, startX, startY, size, size, 0, 0, maxDim, maxDim);

          // Compress to JPEG data URL
          const dataUrl = canvas.toDataURL('image/jpeg', quality);
          resolve(dataUrl);
        } catch (canvasErr) {
          reject(new Error('Image processing error: ' + canvasErr.message));
        }
      };
      img.src = e.target.result;
    };
    reader.readAsDataURL(file);
  });
}

// ==========================================================================
// 12. Command Palette Controller (Ctrl+K / Cmd+K)
// ==========================================================================
let _cmdPaletteInitialized = false;

function initCommandPalette() {
  if (_cmdPaletteInitialized) return;
  _cmdPaletteInitialized = true;

  // Global Keyboard listener for Ctrl+K / Cmd+K
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      toggleCommandPalette();
    } else if (e.key === 'Escape') {
      closeCommandPalette();
    }
  });
}

function openCommandPalette() {
  const backdrop = document.getElementById('cmdPaletteBackdrop');
  if (!backdrop) return;
  backdrop.style.setProperty('display', 'flex', 'important');
  backdrop.classList.add('active');
  const input = document.getElementById('cmdPaletteInput');
  if (input) {
    input.value = '';
    input.focus();
    if (typeof renderCommandResults === 'function') {
      renderCommandResults('');
    }
  }
}

function closeCommandPalette() {
  const backdrop = document.getElementById('cmdPaletteBackdrop');
  if (backdrop) {
    backdrop.classList.remove('active');
    backdrop.style.setProperty('display', 'none', 'important');
  }
}

function toggleCommandPalette() {
  const backdrop = document.getElementById('cmdPaletteBackdrop');
  if (!backdrop) return;
  if (backdrop.classList.contains('active') && backdrop.style.display !== 'none') {
    closeCommandPalette();
  } else {
    openCommandPalette();
  }
}

// Auto-init on script load
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    initCommandPalette();
    initPwaEngine();
    const backdrop = document.getElementById('cmdPaletteBackdrop');
    if (backdrop) backdrop.style.setProperty('display', 'none', 'important');
  });
} else {
  initCommandPalette();
  initPwaEngine();
  const backdrop = document.getElementById('cmdPaletteBackdrop');
  if (backdrop) backdrop.style.setProperty('display', 'none', 'important');
}

// ==========================================================================
// 12. Progressive Web App (PWA) Manager & Service Worker Registration
// ==========================================================================
let deferredPwaPrompt = null;
let pwaInstallBannerShown = false;

function initPwaEngine() {
  // 1. Register Service Worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('./sw.js')
        .then((reg) => {
          console.log('[TGC PWA] Service Worker registered successfully:', reg.scope);
          reg.addEventListener('updatefound', () => {
            const installingWorker = reg.installing;
            if (installingWorker) {
              installingWorker.addEventListener('statechange', () => {
                if (installingWorker.state === 'installed' && navigator.serviceWorker.controller) {
                  showToast('App update available! Refresh to get latest features.', 'info');
                }
              });
            }
          });
        })
        .catch((err) => {
          console.warn('[TGC PWA] Service Worker registration failed:', err);
        });
    });
  }

  // 2. Intercept beforeinstallprompt for native install banner
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPwaPrompt = e;
    console.log('[TGC PWA] beforeinstallprompt event captured');

    const dismissedAt = localStorage.getItem('tgc_pwa_install_dismissed_at');
    if (dismissedAt && (Date.now() - parseInt(dismissedAt, 10)) < 3 * 24 * 60 * 60 * 1000) {
      return;
    }

    setTimeout(() => {
      showPwaInstallBanner();
    }, 2000);
  });

  // 3. Listen to appinstalled event
  window.addEventListener('appinstalled', () => {
    deferredPwaPrompt = null;
    hidePwaInstallBanner();
    showToast('TGC Connect installed successfully!', 'success');
    playSound('success');
  });

  // 4. iOS Safari Add to Home Screen Hint
  const isIos = () => /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());
  const isInStandaloneMode = () => ('standalone' in window.navigator) && (window.navigator.standalone);

  if (isIos() && !isInStandaloneMode()) {
    const iosDismissed = localStorage.getItem('tgc_pwa_ios_dismissed_at');
    if (!iosDismissed || (Date.now() - parseInt(iosDismissed, 10)) > 7 * 24 * 60 * 60 * 1000) {
      setTimeout(() => {
        showIosPwaInstallHint();
      }, 5000);
    }
  }
}

function showPwaInstallBanner() {
  if (pwaInstallBannerShown || document.getElementById('tgcPwaInstallBanner')) return;
  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) return;

  pwaInstallBannerShown = true;
  const banner = document.createElement('div');
  banner.id = 'tgcPwaInstallBanner';
  banner.className = 'pwa-install-banner';
  banner.innerHTML = `
    <div class="pwa-install-icon">
      <img src="assets/icons/icon-96x96.png" alt="App Icon" onerror="this.src='assets/logo_icon.png'">
    </div>
    <div class="pwa-install-info">
      <div class="pwa-install-title">Install TGC Connect</div>
      <div class="pwa-install-desc">Fast attendance punch, calendar & payroll on your home screen.</div>
    </div>
    <button type="button" class="pwa-install-btn" id="btnPwaInstallAction">Install</button>
    <button type="button" class="pwa-install-close" id="btnPwaInstallDismiss" title="Dismiss">&times;</button>
  `;
  document.body.appendChild(banner);

  document.getElementById('btnPwaInstallAction').addEventListener('click', async () => {
    playSound('click');
    if (deferredPwaPrompt) {
      deferredPwaPrompt.prompt();
      const choice = await deferredPwaPrompt.userChoice;
      if (choice && choice.outcome === 'accepted') {
        playSound('success');
      }
      deferredPwaPrompt = null;
    }
    hidePwaInstallBanner();
  });

  document.getElementById('btnPwaInstallDismiss').addEventListener('click', () => {
    localStorage.setItem('tgc_pwa_install_dismissed_at', Date.now().toString());
    hidePwaInstallBanner();
  });
}

function hidePwaInstallBanner() {
  const banner = document.getElementById('tgcPwaInstallBanner');
  if (banner) {
    banner.style.transition = 'all 0.25s ease';
    banner.style.opacity = '0';
    banner.style.transform = 'translateY(20px)';
    setTimeout(() => banner.remove(), 260);
  }
}

function showIosPwaInstallHint() {
  if (document.getElementById('tgcIosPwaHint')) return;
  const hint = document.createElement('div');
  hint.id = 'tgcIosPwaHint';
  hint.className = 'pwa-install-banner';
  hint.innerHTML = `
    <div class="pwa-install-icon">
      <img src="assets/icons/icon-96x96.png" alt="App Icon">
    </div>
    <div class="pwa-install-info">
      <div class="pwa-install-title">Install on iPhone</div>
      <div class="pwa-install-desc">Tap <strong>Share <i style="font-style: normal;">⎋</i></strong> then <strong>'Add to Home Screen' <i style="font-style: normal;">➕</i></strong></div>
    </div>
    <button type="button" class="pwa-install-close" onclick="localStorage.setItem('tgc_pwa_ios_dismissed_at', Date.now()); document.getElementById('tgcIosPwaHint').remove();">&times;</button>
  `;
  document.body.appendChild(hint);
}

// Global trigger function to prompt install anywhere in app
window.triggerPwaInstall = async function() {
  playSound('click');

  // If already running in standalone mode (installed)
  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
    showToast('TGC Connect is already installed and running as an app!', 'success');
    return;
  }

  // 1. If native deferredPwaPrompt is ready, prompt immediately!
  if (deferredPwaPrompt) {
    try {
      deferredPwaPrompt.prompt();
      const choice = await deferredPwaPrompt.userChoice;
      if (choice && choice.outcome === 'accepted') {
        playSound('success');
        showToast('Installing TGC Connect...', 'success');
      }
      deferredPwaPrompt = null;
      hidePwaInstallBanner();
    } catch (e) {
      console.warn('Install prompt error:', e);
    }
    return;
  }

  // 2. iOS Safari
  const isIos = /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());
  if (isIos) {
    showIosInstallModal();
    return;
  }

  // 3. Fallback guide modal for Desktop Chrome/Edge/Firefox
  showGenericInstallModal();
};

function showIosInstallModal() {
  const existing = document.getElementById('tgcIosGuideModal');
  if (existing) existing.remove();

  const modal = document.createElement('div');
  modal.id = 'tgcIosGuideModal';
  modal.className = 'pwa-guide-modal-overlay';
  modal.innerHTML = `
    <div class="pwa-guide-modal-box">
      <div class="pwa-guide-header">
        <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
          📲
        </div>
        <div>
          <div style="font-size: 1.15rem; font-weight: 800;">Install TGC Connect</div>
          <div style="font-size: 0.78rem; color: #cbd5e1;">Add to your iPhone or iPad Home Screen</div>
        </div>
        <button type="button" class="pwa-guide-close" onclick="document.getElementById('tgcIosGuideModal').remove()">&times;</button>
      </div>
      <div class="pwa-guide-body">
        <div class="pwa-step-card">
          <div class="pwa-step-num">1</div>
          <div style="font-size: 0.88rem; color: #1e293b; font-weight: 600;">
            Tap the <strong>Share</strong> button <i style="font-style: normal; font-size: 1.1rem; vertical-align: middle;">⎋</i> in Safari's bottom toolbar.
          </div>
        </div>
        <div class="pwa-step-card">
          <div class="pwa-step-num">2</div>
          <div style="font-size: 0.88rem; color: #1e293b; font-weight: 600;">
            Scroll down the share sheet and tap <strong>Add to Home Screen</strong> <i style="font-style: normal; font-size: 1rem; vertical-align: middle;">➕</i>.
          </div>
        </div>
        <div class="pwa-step-card">
          <div class="pwa-step-num">3</div>
          <div style="font-size: 0.88rem; color: #1e293b; font-weight: 600;">
            Tap <strong>Add</strong> in the top-right corner to finish installing.
          </div>
        </div>
        <button type="button" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 0.75rem;" onclick="document.getElementById('tgcIosGuideModal').remove()">
          Got It
        </button>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
}

function showGenericInstallModal() {
  const existing = document.getElementById('tgcGenericGuideModal');
  if (existing) existing.remove();

  const modal = document.createElement('div');
  modal.id = 'tgcGenericGuideModal';
  modal.className = 'pwa-guide-modal-overlay';
  modal.innerHTML = `
    <div class="pwa-guide-modal-box">
      <div class="pwa-guide-header">
        <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
          📲
        </div>
        <div>
          <div style="font-size: 1.15rem; font-weight: 800;">Install TGC Connect App</div>
          <div style="font-size: 0.78rem; color: #cbd5e1;">Available for Desktop & Mobile</div>
        </div>
        <button type="button" class="pwa-guide-close" onclick="document.getElementById('tgcGenericGuideModal').remove()">&times;</button>
      </div>
      <div class="pwa-guide-body">
        <div class="pwa-step-card">
          <div class="pwa-step-num">1</div>
          <div style="font-size: 0.88rem; color: #1e293b; font-weight: 600;">
            Look for the <strong>Install App icon (⊕ or 📲)</strong> on the right side of your browser's address bar.
          </div>
        </div>
        <div class="pwa-step-card">
          <div class="pwa-step-num">2</div>
          <div style="font-size: 0.88rem; color: #1e293b; font-weight: 600;">
            Or click your browser's <strong>Menu (⋮ or ⋯)</strong> and select <strong>"Install TGC Connect"</strong> or <strong>"Add to Home Screen"</strong>.
          </div>
        </div>
        <div style="display: flex; gap: 0.5rem;">
          <button type="button" class="btn btn-primary" style="flex: 1; justify-content: center;" onclick="if(deferredPwaPrompt){deferredPwaPrompt.prompt();}; document.getElementById('tgcGenericGuideModal').remove();">
            Try Native Prompt
          </button>
          <button type="button" class="btn btn-secondary" style="flex: 1; justify-content: center;" onclick="document.getElementById('tgcGenericGuideModal').remove();">
            Close
          </button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
}


