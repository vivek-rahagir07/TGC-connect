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

document.addEventListener('click', (e) => {
  if (e.target.classList && e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('active');
  }
});

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
        <div class="user-nav-dropdown" id="userNavDropdownWrap">
          <div class="user-nav-trigger" id="userNavTriggerBtn" onclick="toggleUserDropdown(event)">
            <div class="avatar-sm" style="width: 34px; height: 34px; min-width: 34px;">
              ${u.photo_url ? `<img src="${u.photo_url}" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">` : escapeHtml(u.name.charAt(0).toUpperCase())}
            </div>
            <div style="line-height: 1.2; text-align: left;">
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
        <a href="login.html" class="btn btn-secondary btn-sm">Sign In</a>
        <a href="register.html" class="btn btn-primary btn-sm">Onboard Employee</a>
      `;
    }
  }

  if (window.lucide) {
    lucide.createIcons();
  }
}

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
      <div class="modal-footer" style="padding: 0.85rem 1.25rem; background: #fafafa; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end;">
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
