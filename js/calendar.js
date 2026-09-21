/**
 * TGC Connect - Academic & Holiday Calendar Engine (Tabular Calendar Map)
 * Full interactive HTML table-based month-wise calendar map with:
 * - Next / Previous month navigation & Today jump
 * - Sundays in red (column header and cells)
 * - Academic and official holidays marked with a LIGHT RED CIRCLE
 * - Direct in-calendar editing (Click to Add, Click to Edit, Delete) for Admin!
 * - Self-contained scoped styles to guarantee flawless rendering across all browsers
 */

(function() {
  // Built-in academic and gazetted holidays fallback database
  const DEFAULT_HOLIDAYS = [
    // 2026 Official Company Holidays
    { id: 49, title: "New Year", holiday_date: "2026-01-01", type: "national", description: "Official Holiday (Thursday)" },
    { id: 50, title: "Republic Day", holiday_date: "2026-01-26", type: "national", description: "National Holiday (Monday)" },
    { id: 51, title: "Holi / Dhulivandan", holiday_date: "2026-03-03", type: "festival", description: "Festival Holiday (Tuesday)" },
    { id: 52, title: "Id-ul-Fitr (Ramzan Id)", holiday_date: "2026-03-21", type: "festival", description: "Festival Holiday (Saturday)" },
    { id: 53, title: "Good Friday", holiday_date: "2026-04-03", type: "national", description: "Official Holiday (Friday)" },
    { id: 54, title: "Id-ul-Zuha (Bakri-id)", holiday_date: "2026-05-27", type: "festival", description: "Festival Holiday (Wednesday)" },
    { id: 55, title: "Muharram", holiday_date: "2026-06-26", type: "festival", description: "Festival Holiday (Friday)" },
    { id: 56, title: "Independence Day", holiday_date: "2026-08-15", type: "national", description: "National Holiday (Saturday)" },
    { id: 57, title: "Raksha Bandhan (RH)", holiday_date: "2026-08-28", type: "festival", description: "Restricted Holiday (Friday)" },
    { id: 58, title: "Mahatma Gandhi's Birthday", holiday_date: "2026-10-02", type: "national", description: "National Holiday (Friday)" },
    { id: 59, title: "Dussehra (Vijayadashami)", holiday_date: "2026-10-20", type: "festival", description: "Festival Holiday (Tuesday)" },
    { id: 60, title: "Dhantrayodashi (RH)", holiday_date: "2026-11-06", type: "festival", description: "Restricted Holiday (Friday)" },
    { id: 61, title: "Diwali (Deepavali)", holiday_date: "2026-11-08", type: "festival", description: "Festival Holiday (Sunday)" },
    { id: 62, title: "Govardhan Puja(RH)", holiday_date: "2026-11-10", type: "festival", description: "Restricted Holiday (Tuesday)" },
    { id: 63, title: "Bhaidooj/ Balipratipada(RH)", holiday_date: "2026-11-11", type: "festival", description: "Restricted Holiday (Wednesday)" },
    { id: 64, title: "Guru Nanak's Birthday", holiday_date: "2026-11-24", type: "festival", description: "Festival Holiday (Tuesday)" },
    { id: 65, title: "Christmas Day", holiday_date: "2026-12-25", type: "festival", description: "Official Holiday (Friday)" },

    // 2027
    { id: 101, title: "New Year", holiday_date: "2027-01-01", type: "national", description: "New Year 2027 Celebration" },
    { id: 102, title: "Republic Day", holiday_date: "2027-01-26", type: "national", description: "National Holiday - Republic Day of India" },
    { id: 103, title: "Holi / Dhulivandan", holiday_date: "2027-03-22", type: "festival", description: "Festival of Colours" },
    { id: 104, title: "Independence Day", holiday_date: "2027-08-15", type: "national", description: "National Holiday - Independence Day" },
    { id: 105, title: "Mahatma Gandhi's Birthday", holiday_date: "2027-10-02", type: "national", description: "National Holiday - Gandhi Jayanti" },
    { id: 106, title: "Diwali (Deepavali)", holiday_date: "2027-10-29", type: "festival", description: "Festival of Lights" },
    { id: 107, title: "Christmas Day", holiday_date: "2027-12-25", type: "festival", description: "Christmas Celebration" }
  ];

  // Self-inject guaranteed CSS styles to prevent any caching issue
  function injectCalendarStyles() {
    if (document.getElementById('academic-calendar-injected-styles')) return;
    const styleEl = document.createElement('style');
    styleEl.id = 'academic-calendar-injected-styles';
    styleEl.textContent = `
      .cal-map-wrapper {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 1.5rem;
        box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      }

      .cal-top-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
        padding-bottom: 0.85rem;
        border-bottom: 1px solid #f1f5f9;
      }

      .cal-title-section {
        display: flex;
        align-items: center;
        gap: 0.85rem;
      }

      .cal-title-icon-box {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background: #eef2ff;
        color: #4f46e5;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
      }

      .cal-month-title {
        font-size: 1.6rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.5px;
        margin: 0;
        line-height: 1.2;
      }

      .cal-subtitle {
        font-size: 0.82rem;
        color: #64748b;
        font-weight: 600;
        margin-top: 2px;
      }

      .cal-controls-wrap {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
      }

      .cal-arrow-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #1e293b;
        font-size: 1.1rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
      }

      .cal-arrow-btn:hover {
        background: #eef2ff;
        border-color: #4f46e5;
        color: #4f46e5;
        transform: translateY(-1px);
      }

      .cal-dropdown-select {
        padding: 0.55rem 0.85rem;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #ffffff;
        font-size: 0.9rem;
        font-weight: 700;
        color: #1e293b;
        cursor: pointer;
        outline: none;
      }

      .cal-dropdown-select:focus {
        border-color: #4f46e5;
      }

      .cal-today-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.55rem 0.95rem;
        border-radius: 8px;
        border: 1px solid #4f46e5;
        background: #4f46e5;
        color: #ffffff;
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
      }

      .cal-today-btn:hover {
        background: #4338ca;
        transform: translateY(-1px);
      }

      .cal-add-holiday-quick-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.55rem 0.95rem;
        border-radius: 8px;
        border: 1px solid #10b981;
        background: #10b981;
        color: #ffffff;
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
      }

      .cal-add-holiday-quick-btn:hover {
        background: #059669;
        transform: translateY(-1px);
      }

      /* KPI Stat Grid */
      .cal-kpi-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.85rem;
      }

      .cal-kpi-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.85rem 1.1rem;
        display: flex;
        flex-direction: column;
        gap: 0.2rem;
      }

      .cal-kpi-label {
        font-size: 0.74rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .cal-kpi-num {
        font-size: 1.55rem;
        font-weight: 800;
        font-family: 'JetBrains Mono', monospace;
        color: #0f172a;
      }

      .cal-kpi-card.kpi-red .cal-kpi-num {
        color: #dc2626;
      }

      .cal-kpi-card.kpi-holiday .cal-kpi-num {
        color: #b91c1c;
      }

      .cal-kpi-card.kpi-green .cal-kpi-num {
        color: #15803d;
      }

      /* Tabular Calendar Map Structure */
      .cal-table-responsive {
        width: 100%;
        overflow-x: auto;
        border-radius: 12px;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
      }

      .cal-table-map {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        min-width: 680px;
      }

      /* Column Headers */
      .cal-table-map thead th {
        background: #f1f5f9;
        color: #475569;
        font-size: 0.82rem;
        font-weight: 800;
        letter-spacing: 0.8px;
        text-transform: uppercase;
        text-align: center;
        padding: 0.85rem 0.5rem;
        border-bottom: 2px solid #cbd5e1;
        border-right: 1px solid #e2e8f0;
      }

      .cal-table-map thead th:last-child {
        border-right: none;
      }

      /* SUNDAY COLUMN HEADER IN RED */
      .cal-table-map thead th.th-sunday {
        background: #fee2e2;
        color: #dc2626;
        border-bottom: 2px solid #f87171;
      }

      /* Table Body Day Cells */
      .cal-table-map tbody td {
        height: 108px;
        min-height: 108px;
        vertical-align: top;
        padding: 0.6rem;
        border-right: 1px solid #e2e8f0;
        border-bottom: 1px solid #e2e8f0;
        position: relative;
        background: #ffffff;
        transition: background 0.15s ease;
        cursor: pointer;
      }

      .cal-table-map tbody td:last-child {
        border-right: none;
      }

      .cal-table-map tbody tr:last-child td {
        border-bottom: none;
      }

      .cal-table-map tbody td:hover {
        background: #f8fafc;
      }

      /* SUNDAY CELL IN RED */
      .cal-table-map tbody td.td-sunday {
        background: #fff8f8;
      }

      .cal-table-map tbody td.td-sunday:hover {
        background: #fef2f2;
      }

      .cal-table-map tbody td.td-sunday .cal-date-number {
        color: #dc2626;
        font-weight: 800;
      }

      /* TODAY CELL */
      .cal-table-map tbody td.td-today {
        background: #f5f7ff;
        box-shadow: inset 0 0 0 2px #4f46e5;
      }

      /* OTHER MONTH CELL (Faded) */
      .cal-table-map tbody td.td-other-month {
        background: #fafafa;
        opacity: 0.38;
        cursor: default;
      }

      /* Date Header Inside Cell */
      .cal-cell-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.35rem;
      }

      .cal-date-number {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        font-family: 'JetBrains Mono', monospace;
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.2s ease;
      }

      /* =======================================================
         HOLIDAY DATE NUMBER WITH LIGHT RED CIRCLE
         ======================================================= */
      .cal-table-map tbody td.td-holiday .cal-date-number {
        background: #fee2e2 !important;
        border: 2px solid #ef4444 !important;
        color: #991b1b !important;
        font-weight: 800 !important;
        box-shadow: 0 2px 6px rgba(239, 68, 68, 0.28);
      }

      .cal-badge-today-chip {
        font-size: 0.65rem;
        font-weight: 800;
        text-transform: uppercase;
        color: #ffffff;
        background: #4f46e5;
        padding: 2px 6px;
        border-radius: 8px;
        letter-spacing: 0.3px;
      }

      .cal-badge-sunday-chip {
        font-size: 0.65rem;
        font-weight: 700;
        color: #dc2626;
        background: #fee2e2;
        padding: 1px 5px;
        border-radius: 4px;
      }

      /* Quick Hover Plus button for Admin */
      .cal-cell-add-btn {
        display: none;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: #10b981;
        color: #ffffff;
        font-size: 0.75rem;
        font-weight: 800;
        align-items: center;
        justify-content: center;
        border: none;
        cursor: pointer;
        transition: transform 0.15s;
      }

      .cal-table-map tbody td:hover .cal-cell-add-btn {
        display: inline-flex;
      }

      .cal-cell-add-btn:hover {
        transform: scale(1.2);
        background: #059669;
      }

      /* Holiday Pill Chip in Cell */
      .cal-holiday-stack {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        margin-top: 0.25rem;
      }

      .cal-holiday-pill {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.3rem;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 2px 6px;
        border-radius: 4px;
        line-height: 1.3;
      }

      .cal-holiday-pill-content {
        display: flex;
        align-items: center;
        gap: 0.3rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .cal-holiday-pill.type-national {
        background: #fee2e2;
        border: 1px solid #fca5a5;
        color: #991b1b;
      }

      .cal-holiday-pill.type-festival {
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
      }

      .cal-holiday-pill.type-company {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        color: #1e40af;
      }

      .cal-pill-edit-icon {
        font-size: 0.72rem;
        opacity: 0.7;
        margin-left: 2px;
      }

      .cal-holiday-pill:hover .cal-pill-edit-icon {
        opacity: 1;
      }

      /* Legend Bar */
      .cal-legend-row {
        display: flex;
        align-items: center;
        gap: 1.5rem;
        flex-wrap: wrap;
        padding: 0.85rem 1.1rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.82rem;
        font-weight: 600;
        color: #475569;
      }

      .cal-legend-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
      }

      .circle-light-red-mark {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: #fee2e2;
        border: 2px solid #ef4444;
        display: inline-block;
      }

      .box-sunday-mark {
        width: 18px;
        height: 18px;
        border-radius: 4px;
        background: #fee2e2;
        border: 1px solid #fca5a5;
        display: inline-block;
      }

      .ring-today-mark {
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: #eef2ff;
        border: 2px solid #4f46e5;
        display: inline-block;
      }

      /* Click Detail Popover */
      .cal-detail-card {
        display: none;
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 12px;
        padding: 1.25rem;
        box-shadow: 0 8px 24px -4px rgba(0,0,0,0.1);
        animation: fadeIn 0.2s ease-in-out;
      }

      /* Monthly Holiday Agenda - 2 in one line side-by-side */
      .cal-agenda-section {
        margin-top: 0.75rem;
      }

      .cal-agenda-heading {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-bottom: 0.85rem;
      }

      .cal-agenda-heading-left {
        font-size: 1.05rem;
        font-weight: 800;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 0.5rem;
      }

      .cal-agenda-toggle-pills {
        display: inline-flex;
        background: #f1f5f9;
        padding: 3px;
        border-radius: 8px;
        gap: 3px;
      }

      .cal-toggle-pill {
        border: none;
        background: transparent;
        font-size: 0.76rem;
        font-weight: 700;
        color: #64748b;
        padding: 4px 10px;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s ease;
      }

      .cal-toggle-pill.active {
        background: #ffffff;
        color: #0f172a;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
      }

      .cal-agenda-cards-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem 1rem;
      }

      @media (max-width: 768px) {
        .cal-agenda-cards-grid {
          grid-template-columns: 1fr;
        }
      }

      .cal-agenda-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.65rem 0.85rem;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
      }

      .cal-agenda-item:hover {
        border-color: #fca5a5;
        background: #fffafa;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(225, 29, 72, 0.08);
      }

      .cal-agenda-daybox {
        width: 42px;
        min-width: 42px;
        height: 42px;
        border-radius: 8px;
        background: #fff1f2;
        border: 1.5px solid #fecdd3;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: #e11d48;
        font-family: 'JetBrains Mono', monospace;
        flex-shrink: 0;
      }

      .cal-agenda-daynum {
        font-size: 1.05rem;
        font-weight: 800;
        line-height: 1;
      }

      .cal-agenda-wkday {
        font-size: 0.58rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        color: #be123c;
      }

      .cal-badge-type {
        font-size: 0.68rem;
        font-weight: 700;
        padding: 1px 6px;
        border-radius: 4px;
        display: inline-block;
        white-space: nowrap;
      }

      .cal-badge-type.cal-badge-national {
        background: #fee2e2;
        color: #991b1b;
      }

      .cal-badge-type.cal-badge-festival {
        background: #fef3c7;
        color: #92400e;
      }

      .cal-badge-type.cal-badge-company {
        background: #dbeafe;
        color: #1e40af;
      }

      /* In-Calendar Modal Dialog (For Direct Editing - Zero Grey Screen & Fully Centered) */
      .cal-modal-backdrop {
        position: fixed !important;
        inset: 0 !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        background: transparent !important;
        background-color: transparent !important;
        backdrop-filter: none !important;
        -webkit-backdrop-filter: none !important;
        z-index: 99999 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        padding: 1.5rem !important;
        box-sizing: border-box !important;
        margin: 0 !important;
      }

      .cal-modal-backdrop[style*="display: none"] {
        display: none !important;
      }

      .cal-modal-box {
        background: #ffffff !important;
        border-radius: 16px !important;
        width: 100% !important;
        max-width: 480px !important;
        box-shadow: 0 25px 60px -10px rgba(15, 23, 42, 0.35), 0 0 0 1px rgba(15, 23, 42, 0.08) !important;
        border: 1px solid #cbd5e1 !important;
        overflow: hidden !important;
        position: relative !important;
        margin: auto !important;
        animation: calModalZoomIn 0.18s cubic-bezier(0.16, 1, 0.3, 1) !important;
      }

      @keyframes calModalZoomIn {
        from {
          opacity: 0;
          transform: scale(0.95) translateY(-8px);
        }
        to {
          opacity: 1;
          transform: scale(1) translateY(0);
        }
      }

      .cal-modal-header {
        padding: 1.2rem 1.5rem;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f8fafc;
      }

      .cal-modal-header h3 {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 800;
        color: #0f172a;
      }

      .cal-modal-close {
        background: transparent;
        border: none;
        font-size: 1.5rem;
        line-height: 1;
        color: #64748b;
        cursor: pointer;
      }

      .cal-modal-close:hover {
        color: #0f172a;
      }

      .cal-modal-body {
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
      }

      .cal-form-group {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
      }

      .cal-form-group label {
        font-size: 0.82rem;
        font-weight: 700;
        color: #334155;
      }

      .cal-form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
      }

      .cal-form-input {
        width: 100%;
        padding: 0.65rem 0.85rem;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 0.9rem;
        color: #0f172a;
        font-family: inherit;
        outline: none;
        transition: border-color 0.2s;
        box-sizing: border-box;
      }

      .cal-form-input:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
      }

      .cal-modal-footer {
        padding: 1rem 1.5rem;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
      }

      .cal-btn-primary {
        background: #4f46e5;
        color: #ffffff;
        border: none;
        padding: 0.6rem 1.2rem;
        border-radius: 8px;
        font-weight: 700;
        cursor: pointer;
        transition: background 0.15s;
      }

      .cal-btn-primary:hover {
        background: #4338ca;
      }

      .cal-btn-secondary {
        background: #ffffff;
        color: #475569;
        border: 1px solid #cbd5e1;
        padding: 0.6rem 1rem;
        border-radius: 8px;
        font-weight: 700;
        cursor: pointer;
      }

      .cal-btn-secondary:hover {
        background: #f1f5f9;
      }

      .cal-btn-danger {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
        padding: 0.6rem 1rem;
        border-radius: 8px;
        font-weight: 700;
        cursor: pointer;
      }

      .cal-btn-danger:hover {
        background: #fca5a5;
      }

      @media (max-width: 768px) {
        .cal-kpi-grid {
          grid-template-columns: repeat(2, 1fr);
        }
        .cal-table-map tbody td {
          height: 80px;
          min-height: 80px;
          padding: 0.35rem;
        }
        .cal-date-number {
          width: 28px;
          height: 28px;
          font-size: 0.82rem;
        }
        .cal-form-row {
          grid-template-columns: 1fr;
        }
      }
    `;
    document.head.appendChild(styleEl);
  }

  class AcademicCalendar {
    constructor(containerId, options = {}) {
      this.container = typeof containerId === 'string' ? document.getElementById(containerId) : containerId;
      this.options = Object.assign({
        initialYear: new Date().getFullYear(),
        initialMonth: new Date().getMonth(), // 0 = Jan
        showKpis: true,
        showAgenda: true,
        showLegend: true,
        editable: false, // Set to true for admin direct editing
        onDateSelect: null
      }, options);

      this.currentYear = this.options.initialYear;
      this.currentMonth = this.options.initialMonth;
      this.holidaysCache = {};
      this.selectedDate = null;
      this.isLoading = false;
      this.agendaViewMode = 'month'; // 'month' or 'all'

      this.monthNames = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
      ];
      this.weekdayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

      // Populate default holidays cache immediately
      this.populateDefaultHolidays();

      // Attach ESC key dismissal for direct-edit modal
      if (!window.__calEscapeListenerAttached) {
        window.__calEscapeListenerAttached = true;
        document.addEventListener('keydown', (e) => {
          if (e.key === 'Escape') {
            const m = document.getElementById('calEditModal');
            if (m && m.style.display === 'flex') {
              m.style.display = 'none';
            }
          }
        });
      }

      if (this.options.editable) {
        this.ensureEditModal();
      }
    }

    setAgendaView(mode) {
      this.agendaViewMode = mode;
      this.render();
    }

    populateDefaultHolidays() {
      DEFAULT_HOLIDAYS.forEach(h => {
        const yr = parseInt(h.holiday_date.split('-')[0], 10);
        if (!this.holidaysCache[yr]) this.holidaysCache[yr] = [];
        if (!this.holidaysCache[yr].some(existing => existing.holiday_date === h.holiday_date)) {
          this.holidaysCache[yr].push(Object.assign({}, h));
        }
      });
    }

    async init() {
      injectCalendarStyles();
      if (!this.container) return;

      // Render immediately with initial data
      this.render();

      // Fetch dynamic holidays from server API to update/sync with database
      await this.fetchHolidays(this.currentYear);
      this.render();
    }

    async fetchHolidays(year) {
      this.isLoading = true;
      try {
        let holidays = null;
        if (typeof apiRequest === 'function') {
          const res = await apiRequest(`leave.php?action=holidays&year=${year}`);
          if (res && res.ok && res.data && Array.isArray(res.data.holidays)) {
            holidays = res.data.holidays;
          }
        }
        
        if (!holidays) {
          const res = await fetch(`api/leave.php?action=holidays&year=${year}`);
          const json = await res.json();
          if (json && Array.isArray(json.holidays)) {
            holidays = json.holidays;
          }
        }

        if (holidays && holidays.length > 0) {
          // Replace or merge with server database entries
          this.holidaysCache[year] = holidays;
        }
      } catch (e) {
        console.warn('Using calendar holidays for ' + year, e);
      } finally {
        this.isLoading = false;
      }
      return this.holidaysCache[year] || [];
    }

    async prevMonth() {
      if (this.currentMonth === 0) {
        this.currentMonth = 11;
        this.currentYear--;
        this.render();
        await this.fetchHolidays(this.currentYear);
      } else {
        this.currentMonth--;
      }
      this.render();
    }

    async nextMonth() {
      if (this.currentMonth === 11) {
        this.currentMonth = 0;
        this.currentYear++;
        this.render();
        await this.fetchHolidays(this.currentYear);
      } else {
        this.currentMonth++;
      }
      this.render();
    }

    async goToToday() {
      const today = new Date();
      const targetYear = today.getFullYear();
      const targetMonth = today.getMonth();
      const yearChanged = targetYear !== this.currentYear;
      this.currentYear = targetYear;
      this.currentMonth = targetMonth;
      this.render();
      if (yearChanged) {
        await this.fetchHolidays(this.currentYear);
        this.render();
      }
    }

    async setMonthYear(month, year) {
      const yearChanged = parseInt(year, 10) !== this.currentYear;
      this.currentYear = parseInt(year, 10);
      this.currentMonth = parseInt(month, 10);
      this.render();
      if (yearChanged) {
        await this.fetchHolidays(this.currentYear);
        this.render();
      }
    }

    formatDateKey(year, month, day) {
      const m = String(month + 1).padStart(2, '0');
      const d = String(day).padStart(2, '0');
      return `${year}-${m}-${d}`;
    }

    getHolidayForDate(dateStr) {
      const holidays = this.holidaysCache[this.currentYear] || [];
      return holidays.find(h => h.holiday_date === dateStr) || null;
    }

    calculateMonthMetrics() {
      const daysInMonth = new Date(this.currentYear, this.currentMonth + 1, 0).getDate();
      let sundaysCount = 0;
      let holidaysCount = 0;
      let overlapping = 0;

      const holidays = this.holidaysCache[this.currentYear] || [];
      const holidayMap = new Set(holidays.map(h => h.holiday_date));

      for (let day = 1; day <= daysInMonth; day++) {
        const date = new Date(this.currentYear, this.currentMonth, day);
        const isSunday = date.getDay() === 0;
        const dateStr = this.formatDateKey(this.currentYear, this.currentMonth, day);
        const isHoliday = holidayMap.has(dateStr);

        if (isSunday) sundaysCount++;
        if (isHoliday) holidaysCount++;
        if (isSunday && isHoliday) overlapping++;
      }

      const workingDays = daysInMonth - (sundaysCount + holidaysCount - overlapping);

      return {
        daysInMonth,
        sundaysCount,
        holidaysCount,
        workingDays: Math.max(0, workingDays)
      };
    }

    render() {
      if (!this.container) return;

      const metrics = this.calculateMonthMetrics();
      const today = new Date();
      const todayStr = this.formatDateKey(today.getFullYear(), today.getMonth(), today.getDate());

      const firstDayIndex = new Date(this.currentYear, this.currentMonth, 1).getDay(); // 0 = Sun
      const daysInCurrentMonth = new Date(this.currentYear, this.currentMonth + 1, 0).getDate();
      const daysInPrevMonth = new Date(this.currentYear, this.currentMonth, 0).getDate();

      // Holidays this year and this month for agenda
      const allYearHolidays = (this.holidaysCache[this.currentYear] || []).slice().sort((a, b) => a.holiday_date.localeCompare(b.holiday_date));
      const holidaysThisMonth = allYearHolidays.filter(h => {
        const parts = h.holiday_date.split('-');
        return parseInt(parts[0], 10) === this.currentYear && parseInt(parts[1], 10) === (this.currentMonth + 1);
      });

      // Build year dropdown
      const yearOptions = [];
      for (let y = this.currentYear - 2; y <= this.currentYear + 2; y++) {
        yearOptions.push(`<option value="${y}" ${y === this.currentYear ? 'selected' : ''}>${y}</option>`);
      }

      // Build month dropdown
      const monthOptions = this.monthNames.map((name, idx) => {
        return `<option value="${idx}" ${idx === this.currentMonth ? 'selected' : ''}>${name}</option>`;
      }).join('');

      let html = `
        <div class="cal-map-wrapper">
          
          <!-- Top Control Header Bar -->
          <div class="cal-top-bar">
            <div class="cal-title-section">
              <div class="cal-title-icon-box">
                🗓️
              </div>
              <div>
                <h2 class="cal-month-title">${this.monthNames[this.currentMonth]} ${this.currentYear}</h2>
                <div class="cal-subtitle">
                  ${this.options.editable ? 'Official Holiday Calendar &bull; <strong style="color: #4f46e5;">Click any date to Add or Edit</strong>' : 'Academic Calendar & Official Company Schedule'}
                </div>
              </div>
            </div>

            <div class="cal-controls-wrap">
              <button type="button" class="cal-arrow-btn" id="calPrevBtn" title="Previous Month" aria-label="Previous Month">&#10094;</button>
              
              <select class="cal-dropdown-select" id="calMonthSelect" aria-label="Month">
                ${monthOptions}
              </select>

              <select class="cal-dropdown-select" id="calYearSelect" aria-label="Year">
                ${yearOptions.join('')}
              </select>

              <button type="button" class="cal-arrow-btn" id="calNextBtn" title="Next Month" aria-label="Next Month">&#10095;</button>

              <button type="button" class="cal-today-btn" id="calTodayBtn">
                <span>Today</span>
              </button>

              ${this.options.editable ? `
                <button type="button" class="cal-add-holiday-quick-btn" onclick="window.__academicCalendarInstance.openAddModal('${this.formatDateKey(this.currentYear, this.currentMonth, 1)}')">
                  <span>+ Add Holiday</span>
                </button>
              ` : ''}
            </div>
          </div>
      `;

      // KPI Ribbon
      if (this.options.showKpis) {
        html += `
          <div class="cal-kpi-grid">
            <div class="cal-kpi-card">
              <div class="cal-kpi-label">Days in Month</div>
              <div class="cal-kpi-num">${metrics.daysInMonth}</div>
            </div>
            <div class="cal-kpi-card kpi-green">
              <div class="cal-kpi-label">Est. Working Days</div>
              <div class="cal-kpi-num">${metrics.workingDays}</div>
            </div>
            <div class="cal-kpi-card kpi-red">
              <div class="cal-kpi-label">Sundays (Weekly Off)</div>
              <div class="cal-kpi-num">${metrics.sundaysCount}</div>
            </div>
            <div class="cal-kpi-card kpi-holiday">
              <div class="cal-kpi-label">Official & Academic Holidays</div>
              <div class="cal-kpi-num">${metrics.holidaysCount}</div>
            </div>
          </div>
        `;
      }

      // Tabular Calendar Map <table>
      html += `
        <div class="cal-table-responsive">
          <table class="cal-table-map">
            <thead>
              <tr>
                <th class="th-sunday">SUN</th>
                <th>MON</th>
                <th>TUE</th>
                <th>WED</th>
                <th>THU</th>
                <th>FRI</th>
                <th>SAT</th>
              </tr>
            </thead>
            <tbody>
      `;

      // Construct weeks rows (each tr is a week)
      let currentDay = 1;
      let nextMonthDay = 1;
      const totalRows = Math.ceil((firstDayIndex + daysInCurrentMonth) / 7);

      for (let r = 0; r < totalRows; r++) {
        html += `<tr>`;
        for (let col = 0; col < 7; col++) {
          const isSundayCol = (col === 0);

          // Leading days from previous month
          if (r === 0 && col < firstDayIndex) {
            const prevDayNum = daysInPrevMonth - (firstDayIndex - 1 - col);
            html += `
              <td class="td-other-month ${isSundayCol ? 'td-sunday' : ''}">
                <div class="cal-cell-header">
                  <span class="cal-date-number">${prevDayNum}</span>
                </div>
              </td>
            `;
          }
          // Days in current month
          else if (currentDay <= daysInCurrentMonth) {
            const dateStr = this.formatDateKey(this.currentYear, this.currentMonth, currentDay);
            const isToday = (dateStr === todayStr);
            const holiday = this.getHolidayForDate(dateStr);
            const hasHoliday = Boolean(holiday);

            let tdClasses = [];
            if (isSundayCol) tdClasses.push('td-sunday');
            if (hasHoliday) tdClasses.push('td-holiday');
            if (isToday) tdClasses.push('td-today');

            let holidayPillHtml = '';
            if (hasHoliday) {
              const typeClass = `type-${holiday.type || 'company'}`;
              holidayPillHtml = `
                <div class="cal-holiday-pill ${typeClass}" title="${this.escapeHtml(holiday.title)}">
                  <div class="cal-holiday-pill-content">
                    <span>🌸</span>
                    <span>${this.escapeHtml(holiday.title)}</span>
                  </div>
                  ${this.options.editable ? `<span class="cal-pill-edit-icon" title="Edit Holiday">✏️</span>` : ''}
                </div>
              `;
            }

            html += `
              <td class="${tdClasses.join(' ')}" data-date="${dateStr}" onclick="window.__academicCalendarInstance.handleCellClick('${dateStr}')">
                <div class="cal-cell-header">
                  <span class="cal-date-number" title="${hasHoliday ? this.escapeHtml(holiday.title) : (isSundayCol ? 'Sunday Weekly Off' : dateStr)}">
                    ${currentDay}
                  </span>
                  ${isToday ? '<span class="cal-badge-today-chip">Today</span>' : ''}
                  ${(isSundayCol && !hasHoliday) ? '<span class="cal-badge-sunday-chip">Off</span>' : ''}
                  ${(this.options.editable && !hasHoliday) ? `<button type="button" class="cal-cell-add-btn" title="Add Holiday" onclick="event.stopPropagation(); window.__academicCalendarInstance.openAddModal('${dateStr}')">+</button>` : ''}
                </div>
                <div class="cal-holiday-stack">
                  ${holidayPillHtml}
                </div>
              </td>
            `;
            currentDay++;
          }
          // Trailing days from next month
          else {
            html += `
              <td class="td-other-month ${isSundayCol ? 'td-sunday' : ''}">
                <div class="cal-cell-header">
                  <span class="cal-date-number">${nextMonthDay}</span>
                </div>
              </td>
            `;
            nextMonthDay++;
          }
        }
        html += `</tr>`;
      }

      html += `
            </tbody>
          </table>
        </div>
      `;

      // Legend Bar
      if (this.options.showLegend) {
        html += `
          <div class="cal-legend-row">
            <div class="cal-legend-badge">
              <span class="circle-light-red-mark"></span>
              <span><strong>Light Red Circle:</strong> Official / Academic Holiday</span>
            </div>
            <div class="cal-legend-badge">
              <span class="box-sunday-mark"></span>
              <span><strong style="color: #dc2626;">Red Cell / Column:</strong> Sunday (Weekly Off)</span>
            </div>
            <div class="cal-legend-badge">
              <span class="ring-today-mark"></span>
              <span><strong>Indigo Border:</strong> Current Day (Today)</span>
            </div>
            ${this.options.editable ? `
              <div class="cal-legend-badge" style="margin-left: auto; color: #4f46e5;">
                <span>💡 <em>Click any day to add or edit holiday directly</em></span>
              </div>
            ` : ''}
          </div>
        `;
      }

      // Click Detail Card (Read-only view for employees)
      html += `
        <div id="calSelectedDayBox" class="cal-detail-card">
          <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
            <div style="display: flex; align-items: center; gap: 1rem;">
              <div id="calDetailDateBadge" style="width: 54px; height: 54px; border-radius: 10px; background: #fee2e2; border: 2px solid #ef4444; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #991b1b; font-family: 'JetBrains Mono', monospace; font-weight: 800;">
                <span id="calDetailDayNum" style="font-size: 1.45rem; line-height: 1;">--</span>
                <span id="calDetailDayWk" style="font-size: 0.65rem; text-transform: uppercase;">--</span>
              </div>
              <div>
                <div id="calDetailTitle" style="font-size: 1.2rem; font-weight: 800; color: #0f172a;">Holiday Name</div>
                <div id="calDetailSubtitle" style="font-size: 0.88rem; color: #64748b; margin-top: 0.2rem;">Holiday Description</div>
              </div>
            </div>
            <button type="button" class="cal-arrow-btn" style="width: auto; height: auto; padding: 0.4rem 0.8rem; font-size: 0.82rem;" onclick="document.getElementById('calSelectedDayBox').style.display='none'">
              &times; Close
            </button>
          </div>
        </div>
      `;

      // Monthly Agenda Section
      if (this.options.showAgenda) {
        const isAllView = (this.agendaViewMode === 'all');
        const displayHolidays = isAllView ? allYearHolidays : holidaysThisMonth;

        html += `
          <div class="cal-agenda-section">
            <div class="cal-agenda-heading">
              <div class="cal-agenda-heading-left">
                <span>🌸</span>
                <span>${isAllView ? `All Official Company Holidays (${allYearHolidays.length})` : `Official Company Holidays in ${this.monthNames[this.currentMonth]} ${this.currentYear} (${holidaysThisMonth.length})`}</span>
              </div>
              <div class="cal-agenda-toggle-pills">
                <button type="button" class="cal-toggle-pill ${!isAllView ? 'active' : ''}" onclick="window.__academicCalendarInstance.setAgendaView('month')">
                  ${this.monthNames[this.currentMonth]} (${holidaysThisMonth.length})
                </button>
                <button type="button" class="cal-toggle-pill ${isAllView ? 'active' : ''}" onclick="window.__academicCalendarInstance.setAgendaView('all')">
                  All ${this.currentYear} (${allYearHolidays.length})
                </button>
              </div>
            </div>
        `;

        if (displayHolidays.length === 0) {
          html += `
            <div style="background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; padding: 1.25rem 1.5rem; text-align: center; color: #64748b; font-size: 0.88rem;">
              <div>ℹ️ No official company holidays scheduled for ${this.monthNames[this.currentMonth]} ${this.currentYear}.</div>
              <div style="margin-top: 0.6rem;">
                <button type="button" class="cal-today-btn" style="padding: 0.35rem 0.85rem; font-size: 0.8rem; background: #4f46e5; border-color: #4f46e5;" onclick="window.__academicCalendarInstance.setAgendaView('all')">
                  📅 View All ${allYearHolidays.length} Holidays for ${this.currentYear}
                </button>
              </div>
            </div>
          `;
        } else {
          html += `<div class="cal-agenda-cards-grid">`;
          displayHolidays.forEach(h => {
            const dateObj = new Date(h.holiday_date + 'T00:00:00');
            const dayNum = dateObj.getDate();
            const dayName = this.weekdayNames[dateObj.getDay()];
            const monthShort = this.monthNames[dateObj.getMonth()].slice(0, 3);
            const typeLabel = h.type ? (h.type.charAt(0).toUpperCase() + h.type.slice(1)) : 'Holiday';

            html += `
              <div class="cal-agenda-item" onclick="window.__academicCalendarInstance.handleCellClick('${h.holiday_date}')">
                <div class="cal-agenda-daybox">
                  <span class="cal-agenda-daynum">${dayNum}</span>
                  <span class="cal-agenda-wkday">${isAllView ? monthShort : dayName}</span>
                </div>
                <div style="flex: 1; min-width: 0;">
                  <div style="display: flex; align-items: center; gap: 0.35rem; justify-content: space-between;">
                    <span style="font-weight: 800; color: #0f172a; font-size: 0.88rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${this.escapeHtml(h.title)}">
                      ${this.escapeHtml(h.title)}
                    </span>
                    <span class="cal-badge-type cal-badge-${h.type || 'company'}">${typeLabel}</span>
                  </div>
                  <div style="font-size: 0.74rem; color: #64748b; margin-top: 0.2rem; display: flex; align-items: center; gap: 0.35rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <span style="font-family: 'JetBrains Mono', monospace; font-weight: 600; color: #334155;">${h.holiday_date} (${dayName})</span>
                    ${h.description ? `<span>&bull;</span> <span title="${this.escapeHtml(h.description)}">${this.escapeHtml(h.description)}</span>` : ''}
                  </div>
                </div>
                ${this.options.editable ? `
                  <button type="button" class="cal-arrow-btn" style="width: 28px; height: 28px; font-size: 0.75rem; flex-shrink: 0;" title="Edit" onclick="event.stopPropagation(); window.__academicCalendarInstance.openEditModal(${h.id})">
                    ✏️
                  </button>
                ` : ''}
              </div>
            `;
          });
          html += `</div>`;
        }
        html += `</div>`;
      }

      html += `</div>`; // Close cal-map-wrapper

      this.container.innerHTML = html;
      this.attachEventListeners();
      if (this.options.editable) {
        this.ensureEditModal();
      }
    }

    attachEventListeners() {
      const prevBtn = document.getElementById('calPrevBtn');
      const nextBtn = document.getElementById('calNextBtn');
      const todayBtn = document.getElementById('calTodayBtn');
      const monthSelect = document.getElementById('calMonthSelect');
      const yearSelect = document.getElementById('calYearSelect');

      if (prevBtn) prevBtn.addEventListener('click', () => this.prevMonth());
      if (nextBtn) nextBtn.addEventListener('click', () => this.nextMonth());
      if (todayBtn) todayBtn.addEventListener('click', () => this.goToToday());

      if (monthSelect) {
        monthSelect.addEventListener('change', (e) => {
          this.setMonthYear(e.target.value, this.currentYear);
        });
      }

      if (yearSelect) {
        yearSelect.addEventListener('change', (e) => {
          this.setMonthYear(this.currentMonth, e.target.value);
        });
      }
    }

    handleCellClick(dateStr) {
      this.selectedDate = dateStr;
      const holiday = this.getHolidayForDate(dateStr);

      // In admin editable mode, clicking opens the Edit/Add modal directly!
      if (this.options.editable) {
        if (holiday) {
          this.openEditModal(holiday);
        } else {
          this.openAddModal(dateStr);
        }
        return;
      }

      // In employee read-only mode, display detail card below
      const dateObj = new Date(dateStr + 'T00:00:00');
      const isSunday = dateObj.getDay() === 0;

      const detailBox = document.getElementById('calSelectedDayBox');
      if (!detailBox) return;

      const dayNum = dateObj.getDate();
      const dayName = this.weekdayNames[dateObj.getDay()];
      const monthName = this.monthNames[dateObj.getMonth()];

      document.getElementById('calDetailDayNum').textContent = dayNum;
      document.getElementById('calDetailDayWk').textContent = dayName;

      const titleEl = document.getElementById('calDetailTitle');
      const subEl = document.getElementById('calDetailSubtitle');
      const badgeEl = document.getElementById('calDetailDateBadge');

      if (holiday) {
        titleEl.innerHTML = `<span style="color: #991b1b;">🌸 ${this.escapeHtml(holiday.title)}</span> <span style="background: #fee2e2; color: #991b1b; font-size: 0.72rem; padding: 2px 8px; border-radius: 12px; margin-left: 0.5rem; vertical-align: middle;">${this.escapeHtml(holiday.type || 'Official')} Holiday</span>`;
        subEl.innerHTML = `<strong>${dayName}, ${dayNum} ${monthName} ${dateObj.getFullYear()}</strong> &bull; ${this.escapeHtml(holiday.description || 'Official / Academic Holiday')}`;
        badgeEl.style.background = '#fee2e2';
        badgeEl.style.borderColor = '#ef4444';
        badgeEl.style.color = '#991b1b';
      } else if (isSunday) {
        titleEl.innerHTML = `<span style="color: #dc2626;">🔴 Sunday (Weekly Off)</span>`;
        subEl.innerHTML = `<strong>${dayName}, ${dayNum} ${monthName} ${dateObj.getFullYear()}</strong> &bull; Scheduled Weekly Off day.`;
        badgeEl.style.background = '#fee2e2';
        badgeEl.style.borderColor = '#fca5a5';
        badgeEl.style.color = '#dc2626';
      } else {
        titleEl.innerHTML = `<span>Regular Working Day</span>`;
        subEl.innerHTML = `<strong>${dayName}, ${dayNum} ${monthName} ${dateObj.getFullYear()}</strong> &bull; Normal business hours / shift scheduled.`;
        badgeEl.style.background = '#f1f5f9';
        badgeEl.style.borderColor = '#cbd5e1';
        badgeEl.style.color = '#475569';
      }

      detailBox.style.display = 'block';
      detailBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

      if (typeof this.options.onDateSelect === 'function') {
        this.options.onDateSelect(dateStr, holiday, isSunday);
      }
    }

    // Modal Control Methods for Direct Editing
    ensureEditModal() {
      if (!this.options.editable) return null;
      let modal = document.getElementById('calEditModal');
      if (modal) {
        if (modal.parentElement !== document.body) {
          document.body.appendChild(modal);
        }
        return modal;
      }

      modal = document.createElement('div');
      modal.id = 'calEditModal';
      modal.className = 'cal-modal-backdrop';
      modal.style.display = 'none';
      modal.onclick = (e) => {
        if (e.target === modal) this.closeEditModal();
      };
      modal.innerHTML = `
        <div class="cal-modal-box">
          <div class="cal-modal-header">
            <h3 id="calModalTitle">Edit Company Holiday</h3>
            <button type="button" class="cal-modal-close" onclick="window.__academicCalendarInstance.closeEditModal()">&times;</button>
          </div>
          <form onsubmit="window.__academicCalendarInstance.saveHoliday(event)">
            <div class="cal-modal-body">
              <input type="hidden" id="calFormHolidayId" value="">
              
              <div class="cal-form-group">
                <label>Holiday Title *</label>
                <input type="text" id="calFormTitle" class="cal-form-input" required placeholder="e.g. Diwali (Deepavali) or Annual Event">
              </div>

              <div class="cal-form-row">
                <div class="cal-form-group">
                  <label>Date (YYYY-MM-DD) *</label>
                  <input type="date" id="calFormDate" class="cal-form-input" required>
                </div>

                <div class="cal-form-group">
                  <label>Category *</label>
                  <select id="calFormType" class="cal-form-input">
                    <option value="national">National Holiday</option>
                    <option value="festival">Festival Holiday</option>
                    <option value="company">Company / Academic Event</option>
                  </select>
                </div>
              </div>

              <div class="cal-form-group">
                <label>Description / Details</label>
                <input type="text" id="calFormDesc" class="cal-form-input" placeholder="e.g. Restricted Holiday (RH) or Official Holiday">
              </div>
            </div>

            <div class="cal-modal-footer">
              <button type="button" id="calBtnDelete" class="cal-btn-danger" style="display: none;" onclick="window.__academicCalendarInstance.deleteCurrentHoliday()">
                🗑️ Delete Holiday
              </button>
              <div style="display: flex; gap: 0.5rem; margin-left: auto;">
                <button type="button" class="cal-btn-secondary" onclick="window.__academicCalendarInstance.closeEditModal()">Cancel</button>
                <button type="submit" id="calBtnSubmit" class="cal-btn-primary">Save Changes</button>
              </div>
            </div>
          </form>
        </div>
      `;
      document.body.appendChild(modal);
      return modal;
    }

    openAddModal(dateStr) {
      if (!this.options.editable) return;
      const modal = this.ensureEditModal();
      if (!modal) return;

      document.getElementById('calModalTitle').textContent = `Add Holiday on ${dateStr}`;
      document.getElementById('calFormHolidayId').value = '';
      document.getElementById('calFormTitle').value = '';
      document.getElementById('calFormDate').value = dateStr;
      document.getElementById('calFormType').value = 'festival';
      document.getElementById('calFormDesc').value = '';
      document.getElementById('calBtnDelete').style.display = 'none';
      document.getElementById('calBtnSubmit').textContent = 'Add Holiday';
      modal.style.display = 'flex';
      setTimeout(() => {
        const inp = document.getElementById('calFormTitle');
        if (inp) inp.focus();
      }, 50);
    }

    openEditModal(item) {
      if (!this.options.editable) return;
      const modal = this.ensureEditModal();
      if (!modal) return;

      let h = item;
      if (typeof item === 'number' || typeof item === 'string') {
        const all = this.holidaysCache[this.currentYear] || [];
        h = all.find(x => x.id == item);
      }
      if (!h) return;

      document.getElementById('calModalTitle').textContent = `Edit Holiday`;
      document.getElementById('calFormHolidayId').value = h.id || '';
      document.getElementById('calFormTitle').value = h.title || '';
      document.getElementById('calFormDate').value = h.holiday_date || '';
      document.getElementById('calFormType').value = h.type || 'festival';
      document.getElementById('calFormDesc').value = h.description || '';
      document.getElementById('calBtnDelete').style.display = 'inline-flex';
      document.getElementById('calBtnSubmit').textContent = 'Save Changes';
      modal.style.display = 'flex';
      setTimeout(() => {
        const inp = document.getElementById('calFormTitle');
        if (inp) inp.focus();
      }, 50);
    }

    closeEditModal() {
      const modal = document.getElementById('calEditModal');
      if (modal) modal.style.display = 'none';
    }

    async saveHoliday(e) {
      e.preventDefault();
      const id = document.getElementById('calFormHolidayId').value;
      const title = document.getElementById('calFormTitle').value.trim();
      const date = document.getElementById('calFormDate').value;
      const type = document.getElementById('calFormType').value;
      const desc = document.getElementById('calFormDesc').value.trim();

      if (!title || !date) return;

      const payload = {
        id: id ? parseInt(id, 10) : undefined,
        title,
        holiday_date: date,
        type,
        description: desc
      };
      const action = id ? 'update_holiday' : 'add_holiday';

      let res = null;
      if (typeof apiRequest === 'function') {
        res = await apiRequest(`leave.php?action=${action}`, 'POST', payload);
      } else {
        const raw = await fetch(`api/leave.php?action=${action}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const json = await raw.json();
        res = { ok: raw.ok, data: json };
      }

      if (res && (res.ok || (res.data && res.data.success))) {
        if (typeof showToast === 'function') {
          showToast(res.data.message || (id ? 'Holiday updated!' : 'Holiday added!'), 'success');
        }
        this.closeEditModal();

        // Refresh calendar
        const targetYear = parseInt(date.split('-')[0], 10) || this.currentYear;
        delete this.holidaysCache[targetYear];
        await this.fetchHolidays(this.currentYear);
        this.render();

        // Refresh admin table if exists
        if (typeof loadAdminHolidaysTableOnly === 'function') {
          loadAdminHolidaysTableOnly();
        }
      } else {
        const msg = (res && res.data && res.data.message) ? res.data.message : 'Failed to save holiday.';
        if (typeof showToast === 'function') showToast(msg, 'error');
        else alert(msg);
      }
    }

    async deleteCurrentHoliday() {
      const id = document.getElementById('calFormHolidayId').value;
      if (!id) return;
      if (!confirm('Are you sure you want to delete this company holiday?')) return;

      let res = null;
      if (typeof apiRequest === 'function') {
        res = await apiRequest('leave.php?action=delete_holiday', 'POST', { id });
      } else {
        const raw = await fetch('api/leave.php?action=delete_holiday', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id })
        });
        const json = await raw.json();
        res = { ok: raw.ok, data: json };
      }

      if (res && (res.ok || (res.data && res.data.success))) {
        if (typeof showToast === 'function') showToast('Holiday deleted.', 'success');
        this.closeEditModal();
        delete this.holidaysCache[this.currentYear];
        await this.fetchHolidays(this.currentYear);
        this.render();

        if (typeof loadAdminHolidaysTableOnly === 'function') {
          loadAdminHolidaysTableOnly();
        }
      } else {
        const msg = (res && res.data && res.data.message) ? res.data.message : 'Failed to delete holiday.';
        if (typeof showToast === 'function') showToast(msg, 'error');
        else alert(msg);
      }
    }

    escapeHtml(str) {
      if (!str) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }
  }

  // Global helper
  function initAcademicCalendar(containerId, options = {}) {
    const instance = new AcademicCalendar(containerId, options);
    window.__academicCalendarInstance = instance;
    instance.init();
    return instance;
  }

  window.AcademicCalendar = AcademicCalendar;
  window.initAcademicCalendar = initAcademicCalendar;
})();
