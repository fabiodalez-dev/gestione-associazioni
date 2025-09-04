// Main JavaScript file for Associazione Soci Manager
document.addEventListener('DOMContentLoaded', function() {
    console.log('Associazione Soci Manager - System loaded with enhanced responsive UI');
    
    // Mobile Sidebar Management
    initializeMobileSidebar();
    
    // Simple modal management - just ensure they're hidden by default
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        // Force hide on page load
        modal.style.display = 'none';
        
        modal.addEventListener('show.bs.modal', function() {
            // Hide any existing backdrop
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.style.display = 'none');
            
            // Show modal
            this.style.display = 'flex';
            
            // Prevent body scrolling
            document.body.style.overflow = 'hidden';
        });
        
        modal.addEventListener('hidden.bs.modal', function() {
            // Hide modal
            this.style.display = 'none';
            
            // Restore body scrolling
            document.body.style.overflow = '';
        });
        
        // Close modal when clicking on background
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                const modalInstance = bootstrap.Modal.getInstance(this);
                if (modalInstance) {
                    modalInstance.hide();
                } else {
                    this.style.display = 'none';
                    document.body.style.overflow = '';
                }
            }
        });
    });
    
    // Auto-dismiss alerts - handled by GSAP animations now
    // GSAP AnimationManager handles alert animations automatically
    
    // Form validation helper
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredInputs = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredInputs.forEach(input => {
                if (!input.value.trim()) {
                    input.classList.add('is-invalid');
                    isValid = false;
                } else {
                    input.classList.remove('is-invalid');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                showToast('Compila tutti i campi obbligatori', 'error');
            }
        });
    });
    
    // Email validation
    const emailInputs = document.querySelectorAll('input[type="email"]');
    emailInputs.forEach(input => {
        input.addEventListener('blur', function() {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (this.value && !emailRegex.test(this.value)) {
                this.classList.add('is-invalid');
                showToast('Formato email non valido', 'error');
            } else {
                this.classList.remove('is-invalid');
            }
        });
    });
    
    // Confirm delete actions
    const deleteButtons = document.querySelectorAll('button[data-confirm], input[data-confirm]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            const message = this.getAttribute('data-confirm') || 'Sei sicuro di voler procedere?';
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });
    
    // Auto-generate numero socio
    const numeroSocioInput = document.querySelector('input[name="numero_socio"]');
    if (numeroSocioInput && !numeroSocioInput.value) {
        const currentYear = new Date().getFullYear();
        const randomNum = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
        numeroSocioInput.value = `${currentYear}${randomNum}`;
    }
    
    // Search functionality with debounce
    const searchInputs = document.querySelectorAll('input[name="search"]');
    searchInputs.forEach(input => {
        let timeout;
        input.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                if (this.value.length >= 3 || this.value.length === 0) {
                    this.form.submit();
                }
            }, 500);
        });
    });
    
    // Modal focus management
    modals.forEach(modal => {
        modal.addEventListener('shown.bs.modal', function() {
            const firstInput = this.querySelector('input, select, textarea');
            if (firstInput) {
                firstInput.focus();
            }
        });
    });
    
    // Currency formatting
    const currencyInputs = document.querySelectorAll('input[data-currency]');
    currencyInputs.forEach(input => {
        input.addEventListener('blur', function() {
            const value = parseFloat(this.value);
            if (!isNaN(value)) {
                this.value = value.toFixed(2);
            }
        });
    });
    
    // Load notifications
    loadNotifications();
    
    // Refresh notifications every 5 minutes
    setInterval(loadNotifications, 300000);
    
    // Setup page transitions with GSAP
    setupPageTransitions();
});

// Page transition function
function setupPageTransitions() {
    const navLinks = document.querySelectorAll('.nav-link[href*="index.php?page="]');
    
    navLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetUrl = this.href;
            
            if (window.AnimationManager) {
                window.AnimationManager.transitionToPage(targetUrl);
            } else {
                window.location.href = targetUrl;
            }
        });
    });
}

// Load notifications function
function loadNotifications() {
    const notificationsPanel = document.getElementById('notificationsPanel');
    if (!notificationsPanel) return;
    
    fetch('api/notifications.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayNotifications(data.notifications, data.count);
            } else {
                console.error('Error loading notifications:', data.error);
            }
        })
        .catch(error => {
            console.error('Error fetching notifications:', error);
        });
}

function displayNotifications(notifications, count) {
    const panel = document.getElementById('notificationsPanel');
    if (!panel) return;
    
    if (notifications.length === 0) {
        panel.innerHTML = `
            <div class="text-center text-success">
                <i class="bi bi-check-circle"></i>
                <div class="small">Tutto ok!</div>
            </div>
        `;
        return;
    }
    
    let html = '';
    notifications.forEach(notification => {
        const badgeClass = {
            'danger': 'bg-danger',
            'warning': 'bg-warning',
            'info': 'bg-info'
        }[notification.urgency] || 'bg-secondary';
        
        html += `
            <div class="notification-item mb-2 p-2 border rounded ${notification.urgency === 'danger' ? 'border-danger' : ''}">
                <a href="${notification.link}" class="text-decoration-none">
                    <div class="d-flex align-items-start">
                        <i class="bi bi-${notification.icon} me-2 ${notification.urgency === 'danger' ? 'text-danger' : 'text-muted'}"></i>
                        <div class="flex-grow-1">
                            <div class="small fw-bold">${notification.titolo}</div>
                            <div class="small text-muted">${notification.messaggio}</div>
                        </div>
                        <span class="badge ${badgeClass} small">!</span>
                    </div>
                </a>
            </div>
        `;
    });
    
    panel.innerHTML = html;
}

// Enhanced Toast Notification System
function showToast(message, type = 'info', options = {}) {
    const {
        title = null,
        duration = 5000,
        actions = [],
        showProgress = true,
        icon = null
    } = options;
    
    // Determine icon based on type if not provided
    const icons = {
        success: 'bi-check-circle-fill',
        error: 'bi-exclamation-triangle-fill',
        danger: 'bi-exclamation-triangle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill',
        primary: 'bi-info-circle-fill'
    };
    
    const toastIcon = icon || icons[type] || 'bi-info-circle-fill';
    const toastId = 'toast_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    
    let toastHtml = `
        <div class="toast toast-${type}" role="alert" aria-live="assertive" aria-atomic="true" id="${toastId}">
    `;
    
    if (title) {
        toastHtml += `
            <div class="toast-header">
                <i class="toast-icon ${toastIcon}"></i>
                <strong class="me-auto">${title}</strong>
                <small class="toast-time">${new Date().toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' })}</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Chiudi">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        `;
    } else {
        toastHtml += `
            <div class="d-flex align-items-center">
                <div class="toast-body flex-grow-1">
                    <div class="d-flex align-items-start gap-2">
                        <i class="toast-icon ${toastIcon} mt-1"></i>
                        <div>${message}</div>
                    </div>
                </div>
                <button type="button" class="btn-close me-2" data-bs-dismiss="toast" aria-label="Chiudi">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        `;
    }
    
    if (title) {
        toastHtml += `<div class="toast-body">${message}</div>`;
    }
    
    if (actions.length > 0) {
        toastHtml += '<div class="toast-actions">';
        actions.forEach(action => {
            toastHtml += `<button type="button" class="btn btn-${action.variant || 'outline-primary'}" onclick="${action.onClick}">${action.text}</button>`;
        });
        toastHtml += '</div>';
    }
    
    if (showProgress && duration > 0) {
        toastHtml += `<div class="toast-progress" style="width: 100%;"></div>`;
    }
    
    toastHtml += '</div>';
    
    // Create or get toast container
    let toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container';
        document.body.appendChild(toastContainer);
    }
    
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    const toastElement = document.getElementById(toastId);
    
    // Animate entrance with GSAP
    if (window.gsap) {
        gsap.fromTo(toastElement, 
            { 
                x: 100, 
                opacity: 0,
                scale: 0.9
            },
            { 
                x: 0, 
                opacity: 1,
                scale: 1,
                duration: 0.4,
                ease: "back.out(1.7)"
            }
        );
    }
    
    // Initialize Bootstrap toast
    const toast = new bootstrap.Toast(toastElement, {
        autohide: duration > 0,
        delay: duration
    });
    
    // Progress bar animation
    if (showProgress && duration > 0) {
        const progressBar = toastElement.querySelector('.toast-progress');
        if (progressBar && window.gsap) {
            gsap.to(progressBar, {
                width: '0%',
                duration: duration / 1000,
                ease: "none"
            });
        }
    }
    
    // Handle close event
    toastElement.addEventListener('hidden.bs.toast', function() {
        if (window.gsap) {
            gsap.to(this, {
                x: 100,
                opacity: 0,
                scale: 0.9,
                duration: 0.3,
                ease: "power2.in",
                onComplete: () => this.remove()
            });
        } else {
            this.remove();
        }
    });
    
    toast.show();
    return toastElement;
}

// Utility wrapper functions
function showSuccessToast(message, options = {}) {
    return showToast(message, 'success', { title: 'Successo', ...options });
}

function showErrorToast(message, options = {}) {
    return showToast(message, 'error', { title: 'Errore', ...options });
}

function showWarningToast(message, options = {}) {
    return showToast(message, 'warning', { title: 'Attenzione', ...options });
}

function showInfoToast(message, options = {}) {
    return showToast(message, 'info', { title: 'Informazione', ...options });
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('it-IT', {
        style: 'currency',
        currency: 'EUR'
    }).format(amount);
}

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('it-IT');
}

// Mobile Sidebar Management
function initializeMobileSidebar() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (!sidebarToggle || !sidebar || !overlay) return;
    
    // Toggle sidebar on button click
    sidebarToggle.addEventListener('click', function() {
        toggleSidebar();
    });
    
    // Close sidebar when clicking overlay
    overlay.addEventListener('click', function() {
        closeSidebar();
    });
    
    // Close sidebar when clicking on links (mobile)
    const sidebarLinks = sidebar.querySelectorAll('.nav-link');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 768) {
                closeSidebar();
            }
        });
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 768) {
            closeSidebar();
        }
    });
    
    // Handle escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeSidebar();
        }
    });
}

function toggleSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const body = document.body;
    
    if (sidebar.classList.contains('show')) {
        closeSidebar();
    } else {
        openSidebar();
    }
}

function openSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const body = document.body;
    
    sidebar.classList.add('show');
    overlay.classList.add('show');
    body.classList.add('sidebar-open');
    
    // Animate with GSAP if available
    if (window.gsap) {
        gsap.fromTo(sidebar, 
            { x: -280 },
            { x: 0, duration: 0.3, ease: "power2.out" }
        );
        gsap.fromTo(overlay,
            { opacity: 0 },
            { opacity: 1, duration: 0.3, ease: "power2.out" }
        );
    }
}

function closeSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const body = document.body;
    
    if (!sidebar.classList.contains('show')) return;
    
    // Animate with GSAP if available
    if (window.gsap) {
        gsap.to(sidebar, {
            x: -280,
            duration: 0.3,
            ease: "power2.in",
            onComplete: () => {
                sidebar.classList.remove('show');
                body.classList.remove('sidebar-open');
            }
        });
        gsap.to(overlay, {
            opacity: 0,
            duration: 0.3,
            ease: "power2.in",
            onComplete: () => {
                overlay.classList.remove('show');
            }
        });
    } else {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
        body.classList.remove('sidebar-open');
    }
}