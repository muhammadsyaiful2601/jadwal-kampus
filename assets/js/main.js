// Global variables
let ruanganData = {};
let filterState = {};

// Initialize on DOM loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing...');
    
    // Load room data from page attribute
    try {
        const ruanganAttr = document.body.getAttribute('data-ruangan');
        if (ruanganAttr) {
            ruanganData = JSON.parse(ruanganAttr);
            console.log('Room data loaded:', Object.keys(ruanganData).length, 'rooms');
        }
    } catch (e) {
        console.error('Error loading room data:', e);
    }

    // Load filter state
    initializeFilterState();
    
    // Setup auto-refresh
    setupAutoRefresh();
    
    // Setup responsive behavior
    setupResponsiveBehavior();
    
    // Setup event listeners - using event delegation
    setupEventDelegation();
    
    // Update UI based on filter state
    updateFilterUI();
    
    // Optimasi layout untuk mobile
    optimizeMobileLayout();
    
    console.log('Initialization complete');
});

function initializeFilterState() {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        
        filterState = {
            hari: urlParams.get('hari') || null,
            semua_hari: urlParams.get('semua_hari') === '1',
            kelas: urlParams.get('kelas') || null,
            semua_kelas: urlParams.get('semua_kelas') === '1'
        };
        
        console.log('Filter state initialized:', filterState);
    } catch (e) {
        console.error('Error loading filter state:', e);
        filterState = {
            hari: null,
            semua_hari: false,
            kelas: null,
            semua_kelas: false
        };
    }
}

function setupEventDelegation() {
    console.log('Setting up event delegation...');
    
    // Single event listener for all clicks using delegation
    document.addEventListener('click', function(e) {
        // Handle filter tabs
        const filterTab = e.target.closest('.filter-tab');
        if (filterTab) {
            e.preventDefault();
            e.stopPropagation();
            handleFilterTabClick(filterTab);
            return;
        }
        
        // Handle detail buttons
        const detailBtn = e.target.closest('.btn-detail');
        if (detailBtn) {
            e.preventDefault();
            e.stopPropagation();
            handleDetailButtonClick(detailBtn);
            return;
        }
        
        // Handle mobile filter toggle
        const filterToggle = e.target.closest('.filter-toggle-btn');
        if (filterToggle) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
            return;
        }
        
        // Handle sidebar close button
        const closeBtn = e.target.closest('.sidebar-filter .btn-close');
        if (closeBtn) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
            return;
        }
        
        // Handle refresh button
        const refreshBtn = e.target.closest('.btn-refresh');
        if (refreshBtn) {
            e.preventDefault();
            e.stopPropagation();
            refreshCurrentSchedule(e);
            return;
        }
        
        // Handle overlay click
        const overlay = e.target.closest('#sidebarOverlay');
        if (overlay) {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
            return;
        }
        
        // Handle card click untuk mobile (optional)
        const card = e.target.closest('.jadwal-card');
        if (card && window.innerWidth <= 768) {
            const detailBtn = card.querySelector('.btn-detail');
            if (detailBtn) {
                e.preventDefault();
                e.stopPropagation();
                handleDetailButtonClick(detailBtn);
                return;
            }
        }
    });
    
    // Handle keyboard events
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const sidebar = document.getElementById('mobileSidebar');
            if (sidebar && sidebar.classList.contains('show')) {
                toggleSidebar();
            }
        }
    });
    
    // Handle touch events untuk mobile
    document.addEventListener('touchstart', function(e) {
        // Add active state to touched elements
        const target = e.target;
        if (target.closest('.filter-tab') || target.closest('.btn') || target.closest('.jadwal-card')) {
            target.closest('.filter-tab, .btn, .jadwal-card').classList.add('touch-active');
        }
    }, { passive: true });
    
    document.addEventListener('touchend', function(e) {
        // Remove active state
        document.querySelectorAll('.touch-active').forEach(el => {
            el.classList.remove('touch-active');
        });
    }, { passive: true });
}

function handleFilterTabClick(filterTab) {
    const type = filterTab.getAttribute('data-type');
    const value = filterTab.getAttribute('data-value');
    
    // Add loading state
    filterTab.classList.add('loading');
    
    setTimeout(() => {
        if (type === 'hari') {
            filterState.hari = value;
            filterState.semua_hari = false;
        } else if (type === 'semua_hari') {
            filterState.semua_hari = true;
            filterState.hari = null;
        } else if (type === 'kelas') {
            filterState.kelas = value;
            filterState.semua_kelas = false;
        } else if (type === 'semua_kelas') {
            filterState.semua_kelas = true;
            filterState.kelas = null;
        }
        
        applyFilter();
    }, 100);
}

function handleDetailButtonClick(detailBtn) {
    try {
        const scheduleData = detailBtn.getAttribute('data-schedule');
        if (scheduleData) {
            const schedule = JSON.parse(scheduleData);
            showScheduleDetail(schedule);
        }
    } catch (error) {
        console.error('Error parsing schedule data:', error);
        alert('Terjadi kesalahan saat memuat detail jadwal');
    }
}

function showScheduleDetail(schedule) {
    const ruang = ruanganData[schedule.ruang] || {};
    
    const modalBody = document.getElementById('scheduleDetail');
    if (!modalBody) {
        console.error('Modal body not found');
        return;
    }
    
    const waktuParts = schedule.waktu.split(' - ');
    const waktuFormatted = waktuParts.length === 2 ? 
        `${waktuParts[0]} - ${waktuParts[1]}` : schedule.waktu;
    
    const now = new Date();
    const currentTime = now.getHours() * 60 + now.getMinutes();
    const [startHour, startMinute] = waktuParts[0].split(':').map(Number);
    const startTime = startHour * 60 + startMinute;
    const [endHour, endMinute] = waktuParts[1]?.split(':').map(Number) || [0, 0];
    const endTime = endHour * 60 + endMinute;
    
    let statusBadge = '';
    if (currentTime >= startTime && currentTime <= endTime) {
        statusBadge = `<span class="badge bg-success mb-3"><i class="fas fa-play-circle me-1"></i> Sedang Berlangsung</span>`;
    } else if (currentTime < startTime) {
        statusBadge = `<span class="badge bg-primary mb-3"><i class="fas fa-clock me-1"></i> Akan Datang</span>`;
    } else {
        statusBadge = `<span class="badge bg-secondary mb-3"><i class="fas fa-check-circle me-1"></i> Selesai</span>`;
    }
    
    const html = `
        <div class="schedule-detail">
            ${statusBadge}
            <div class="detail-header mb-4">
                <h4 class="text-primary fw-bold mb-3">${escapeHtml(schedule.mata_kuliah)}</h4>
                <div class="row">
                    <div class="col-md-6">
                        <p class="text-muted mb-2">
                            <i class="fas fa-calendar-day me-2"></i>
                            ${schedule.hari}, ${waktuFormatted}
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p class="text-muted mb-2">
                            <i class="fas fa-clock me-2"></i>
                            Jam ke-${schedule.jam_ke}
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="info-card bg-light p-3 rounded-3">
                        <div class="d-flex align-items-center mb-2">
                            <div class="info-icon bg-primary text-white rounded-circle p-2 me-3">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <div>
                                <small class="text-muted d-block">Kelas</small>
                                <strong class="text-dark fs-5">${schedule.kelas}</strong>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-card bg-light p-3 rounded-3">
                        <div class="d-flex align-items-center mb-2">
                            <div class="info-icon bg-success text-white rounded-circle p-2 me-3">
                                <i class="fas fa-door-open"></i>
                            </div>
                            <div>
                                <small class="text-muted d-block">Ruang</small>
                                <strong class="text-dark fs-5">${schedule.ruang}</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="dosen-info mb-4 p-3 bg-primary-light rounded-3">
                <h6 class="mb-3 d-flex align-items-center">
                    <i class="fas fa-user-tie me-2 text-primary"></i>
                    Dosen Pengampu
                </h6>
                <p class="mb-0 fw-semibold fs-5">${escapeHtml(schedule.dosen)}</p>
            </div>
            
            ${ruang.deskripsi ? `
            <div class="ruang-info mb-4 p-3 bg-info-light rounded-3">
                <h6 class="mb-3 d-flex align-items-center">
                    <i class="fas fa-info-circle me-2 text-info"></i>
                    Informasi Ruangan
                </h6>
                <p class="mb-0">${escapeHtml(ruang.deskripsi)}</p>
            </div>
            ` : ''}
            
            ${ruang.foto_path ? `
            <div class="ruang-photo mb-4">
                <h6 class="mb-3 d-flex align-items-center">
                    <i class="fas fa-image me-2 text-warning"></i>
                    Foto Ruangan
                </h6>
                <div class="photo-container position-relative">
                    <img src="${escapeHtml(ruang.foto_path)}" 
                         alt="Ruang ${escapeHtml(schedule.ruang)}" 
                         class="img-fluid rounded-3 w-100" 
                         style="max-height: 300px; object-fit: cover;"
                         onerror="this.onerror=null; this.src='https://via.placeholder.com/800x400/4361ee/ffffff?text=RUANG+${escapeHtml(schedule.ruang)}'">
                </div>
            </div>
            ` : ''}
            
            <div class="schedule-meta mt-4 pt-3 border-top">
                <h6 class="mb-3 d-flex align-items-center">
                    <i class="fas fa-info-circle me-2"></i>
                    Informasi Akademik
                </h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                            <small class="text-muted">Semester</small>
                            <strong class="${schedule.semester === 'GANJIL' ? 'text-warning' : 'text-success'}">
                                ${schedule.semester}
                            </strong>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                            <small class="text-muted">Tahun Akademik</small>
                            <strong>${schedule.tahun_akademik}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    modalBody.innerHTML = html;
    
    const modalElement = document.getElementById('scheduleModal');
    if (modalElement) {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
        
        modalElement.addEventListener('hidden.bs.modal', function() {
            modalBody.innerHTML = '';
        });
    }
}

function updateFilterUI() {
    // Reset all active tabs
    document.querySelectorAll('.filter-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Activate based on filter state
    if (filterState.semua_hari) {
        document.querySelectorAll('.filter-tab[data-type="semua_hari"]').forEach(tab => {
            tab.classList.add('active');
        });
    } else if (filterState.hari) {
        document.querySelectorAll(`.filter-tab[data-type="hari"][data-value="${filterState.hari}"]`).forEach(tab => {
            tab.classList.add('active');
        });
    }
    
    if (filterState.semua_kelas) {
        document.querySelectorAll('.filter-tab[data-type="semua_kelas"]').forEach(tab => {
            tab.classList.add('active');
        });
    } else if (filterState.kelas) {
        document.querySelectorAll(`.filter-tab[data-type="kelas"][data-value="${filterState.kelas}"]`).forEach(tab => {
            tab.classList.add('active');
        });
    }
    
    updateFilterInfoDisplay();
}

function updateFilterInfoDisplay() {
    const hariMap = {
        '1': 'SENIN',
        '2': 'SELASA',
        '3': 'RABU',
        '4': 'KAMIS',
        '5': 'JUMAT'
    };
    
    const hariFilter = filterState.semua_hari ? 'Semua Hari' : 
                      (filterState.hari ? hariMap[filterState.hari] : 'Hari Ini');
    
    const kelasFilter = filterState.semua_kelas ? 'Semua Kelas' : 
                       (filterState.kelas || 'Pilih Kelas');
    
    const infoBox = document.querySelector('.filter-info-display');
    if (infoBox) {
        infoBox.innerHTML = `
            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-primary">
                    <i class="fas fa-calendar me-1"></i> ${hariFilter}
                </span>
                <span class="badge bg-success">
                    <i class="fas fa-users me-1"></i> ${kelasFilter}
                </span>
                ${filterState.semua_hari ? '<span class="badge bg-warning text-dark"><i class="fas fa-eye me-1"></i> Semua Hari Aktif</span>' : ''}
                ${filterState.semua_kelas ? '<span class="badge bg-info"><i class="fas fa-layer-group me-1"></i> Semua Kelas Aktif</span>' : ''}
            </div>
        `;
    }
}

function applyFilter() {
    const params = new URLSearchParams();
    
    if (filterState.semua_hari) {
        params.append('semua_hari', '1');
    } else if (filterState.hari) {
        params.append('hari', filterState.hari);
    }
    
    if (filterState.semua_kelas) {
        params.append('semua_kelas', '1');
    } else if (filterState.kelas) {
        params.append('kelas', filterState.kelas);
    }
    
    // Simpan filter ke localStorage sebelum redirect
    try {
        const filterData = {
            hari: filterState.hari,
            semua_hari: filterState.semua_hari,
            kelas: filterState.kelas,
            semua_kelas: filterState.semua_kelas,
            timestamp: new Date().getTime()
        };
        localStorage.setItem('jadwalFilter', JSON.stringify(filterData));
    } catch (e) {
        console.error('Error saving filter to localStorage:', e);
    }
    
    // Tampilkan loading indicator untuk mobile
    if (window.innerWidth <= 768) {
        showMobileLoading();
    }
    
    window.location.href = 'index.php?' + params.toString();
}

function showMobileLoading() {
    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'mobile-loading-overlay';
    loadingDiv.innerHTML = `
        <div class="mobile-loading-spinner">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-white">Memuat jadwal...</p>
        </div>
    `;
    document.body.appendChild(loadingDiv);
    
    // Add CSS for loading overlay
    if (!document.querySelector('#mobile-loading-style')) {
        const style = document.createElement('style');
        style.id = 'mobile-loading-style';
        style.textContent = `
            .mobile-loading-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
                backdrop-filter: blur(3px);
            }
            .mobile-loading-spinner {
                text-align: center;
                color: white;
            }
        `;
        document.head.appendChild(style);
    }
}

function setupAutoRefresh() {
    setInterval(() => {
        const now = new Date();
        const currentTime = now.toLocaleTimeString('id-ID', { 
            hour: '2-digit', 
            minute: '2-digit',
            hour12: false
        });
        
        const timeBadge = document.getElementById('currentTime');
        if (timeBadge) {
            timeBadge.textContent = currentTime;
        }
        
        const minutes = now.getMinutes();
        if (minutes === 0 || minutes === 30) {
            updateCurrentSchedule();
        }
        
        updateScheduleStatus();
    }, 30000);
}

function setupResponsiveBehavior() {
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            updateScheduleCardLayout();
            optimizeMobileLayout();
        }, 250);
    });
    
    updateScheduleCardLayout();
    optimizeMobileLayout();
}

function updateScheduleCardLayout() {
    const width = window.innerWidth;
    const cards = document.querySelectorAll('.jadwal-card');
    
    if (width < 768) {
        cards.forEach(card => {
            const body = card.querySelector('.jadwal-body');
            if (body) {
                body.style.padding = '12px';
            }
            
            // Optimasi teks untuk mobile
            const mataKuliah = card.querySelector('.jadwal-mata-kuliah');
            if (mataKuliah && mataKuliah.textContent.length > 40) {
                mataKuliah.style.fontSize = '0.9rem';
            }
        });
    }
}

// FUNGSI OPTIMASI MOBILE
function optimizeMobileLayout() {
    if (window.innerWidth <= 768) {
        console.log('Optimizing mobile layout...');
        
        // 1. Optimasi grid jadwal menjadi 2x2
        const jadwalRows = document.querySelectorAll('.jadwal-section .row:not(.grid-initialized)');
        jadwalRows.forEach(row => {
            row.classList.add('grid-initialized');
            row.style.display = 'grid';
            row.style.gridTemplateColumns = 'repeat(2, 1fr)';
            row.style.gap = '10px';
            row.style.margin = '0';
            
            // Reset semua col classes
            const cols = row.querySelectorAll('[class*="col-"]');
            cols.forEach(col => {
                col.className = 'col-mobile-grid';
                col.style.width = '100%';
                col.style.maxWidth = '100%';
                col.style.flex = '0 0 auto';
                col.style.padding = '0';
            });
        });
        
        // 2. Optimasi teks yang terlalu panjang
        const cards = document.querySelectorAll('.jadwal-card');
        cards.forEach(card => {
            // Set tinggi minimal
            card.style.minHeight = '280px';
            
            // Optimasi judul mata kuliah
            const title = card.querySelector('.jadwal-mata-kuliah');
            if (title && title.textContent.length > 40) {
                title.style.fontSize = '0.9rem';
                title.style.lineHeight = '1.3';
                title.style.minHeight = '36px';
                title.style.display = '-webkit-box';
                title.style.webkitLineClamp = '2';
                title.style.webkitBoxOrient = 'vertical';
                title.style.overflow = 'hidden';
                title.style.textOverflow = 'ellipsis';
            }
            
            // Optimasi nama dosen
            const dosenElements = card.querySelectorAll('.jadwal-info span');
            dosenElements.forEach(span => {
                if (span.textContent.length > 20) {
                    span.style.display = 'inline-block';
                    span.style.maxWidth = '120px';
                    span.style.whiteSpace = 'nowrap';
                    span.style.overflow = 'hidden';
                    span.style.textOverflow = 'ellipsis';
                }
            });
            
            // Optimasi button
            const button = card.querySelector('.btn-detail');
            if (button) {
                button.style.fontSize = '0.8rem';
                button.style.padding = '8px 12px';
                button.style.marginTop = 'auto';
            }
        });
        
        // 3. Optimasi current/next schedule
        const currentNext = document.getElementById('currentNextSection');
        if (currentNext) {
            const titles = currentNext.querySelectorAll('h4, h5');
            titles.forEach(title => {
                if (window.innerWidth <= 400) {
                    title.style.fontSize = '0.95rem';
                } else {
                    title.style.fontSize = '1.1rem';
                }
            });
        }
        
        // 4. Optimasi footer
        const footer = document.querySelector('.footer');
        if (footer) {
            footer.style.padding = '25px 0 20px';
            
            const footerTexts = footer.querySelectorAll('p, small');
            footerTexts.forEach(text => {
                text.style.fontSize = '0.85rem';
                text.style.lineHeight = '1.4';
            });
        }
        
        // 5. Tambahkan touch feedback
        document.querySelectorAll('.filter-tab, .btn, .jadwal-card').forEach(el => {
            el.style.cursor = 'pointer';
            el.style.webkitTapHighlightColor = 'transparent';
        });
    }
}

// Utility function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Make functions globally available
window.showScheduleDetail = showScheduleDetail;
window.toggleSidebar = toggleSidebar;
window.handleFilterClick = handleFilterTabClick;
window.applyFilter = applyFilter;
window.updateFilterUI = updateFilterUI;
window.optimizeMobileLayout = optimizeMobileLayout;

// Helper functions for current schedule
function updateCurrentSchedule() {
    console.log('Updating current schedule...');
}

function updateScheduleStatus() {
    console.log('Updating schedule status...');
}