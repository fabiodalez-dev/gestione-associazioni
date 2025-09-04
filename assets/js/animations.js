// animations.js - Modern GSAP Animation System for Associazione Soci Manager
// Clean, performant, and accessible animations

class AnimationManager {
    constructor() {
        this.isInitialized = false;
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.init();
    }

    init() {
        if (this.isInitialized) return;
        
        // Only proceed if GSAP is loaded
        if (typeof gsap === 'undefined') {
            console.warn('GSAP not loaded - animations disabled');
            return;
        }
        
        try {
            // Register GSAP plugins
            if (typeof ScrollTrigger !== 'undefined') {
                gsap.registerPlugin(ScrollTrigger);
            }
            if (typeof TextPlugin !== 'undefined') {
                gsap.registerPlugin(TextPlugin);
            }
            
            // Set default GSAP settings for performance
            gsap.defaults({
                duration: this.reducedMotion ? 0.01 : 0.4,
                ease: "power2.out"
            });
            
            // Performance optimizations
            gsap.config({
                force3D: true,
                nullTargetWarn: false
            });
            
            // Respect user motion preferences
            if (this.reducedMotion) {
                gsap.globalTimeline.timeScale(100); // Nearly instant for reduced motion
                this.makeAllElementsVisible();
            } else {
                // Initialize animations only if motion is allowed
                this.setupAnimations();
            }
            
            this.isInitialized = true;
            console.log('GSAP Animation Manager initialized successfully');
            
        } catch (error) {
            console.error('Error initializing GSAP:', error);
            this.makeAllElementsVisible();
        }
    }

    setupAnimations() {
        // Initialize all animation types except page entry
        this.animateCards();
        this.animateButtons();
        this.animateForms();
        this.setupScrollAnimations();
        this.animateModals();
        this.animateNotifications();
    }

    // Card Animations
    animateCards() {
        const cards = document.querySelectorAll('.card, .table-card');
        if (!cards.length) return;

        // Set initial state for new cards
        const newCards = Array.from(cards).filter(card => 
            !card.hasAttribute('data-animated')
        );

        if (newCards.length > 0) {
            gsap.set(newCards, { 
                opacity: 0,
                y: 20
            });
            
            gsap.to(newCards, {
                opacity: 1,
                y: 0,
                duration: 0.5,
                stagger: 0.1,
                ease: "power2.out",
                onComplete: () => {
                    newCards.forEach(card => card.setAttribute('data-animated', 'true'));
                }
            });
        }

        // Add hover effects
        cards.forEach(card => {
            if (card.hasAttribute('data-hover-setup')) return;
            
            card.addEventListener('mouseenter', () => {
                gsap.to(card, {
                    y: -4,
                    boxShadow: "0 10px 25px rgba(0,0,0,0.15)",
                    duration: 0.3,
                    ease: "power2.out"
                });
            });

            card.addEventListener('mouseleave', () => {
                gsap.to(card, {
                    y: 0,
                    boxShadow: "0 1px 3px rgba(0,0,0,0.1)",
                    duration: 0.3,
                    ease: "power2.out"
                });
            });
            
            card.setAttribute('data-hover-setup', 'true');
        });
    }

    // Button Animations
    animateButtons() {
        const buttons = document.querySelectorAll('.btn:not([data-btn-animated])');
        
        buttons.forEach(button => {
            button.addEventListener('mouseenter', () => {
                gsap.to(button, {
                    scale: 1.02,
                    duration: 0.2,
                    ease: "power2.out"
                });
            });

            button.addEventListener('mouseleave', () => {
                gsap.to(button, {
                    scale: 1,
                    duration: 0.2,
                    ease: "power2.out"
                });
            });

            button.addEventListener('mousedown', () => {
                gsap.to(button, {
                    scale: 0.98,
                    duration: 0.1,
                    ease: "power2.out"
                });
            });

            button.addEventListener('mouseup', () => {
                gsap.to(button, {
                    scale: 1.02,
                    duration: 0.1,
                    ease: "power2.out"
                });
            });
            
            // Add ripple effect on click
            button.addEventListener('click', (e) => {
                const ripple = document.createElement('span');
                ripple.className = 'btn-ripple';
                button.appendChild(ripple);

                const rect = button.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;

                Object.assign(ripple.style, {
                    position: 'absolute',
                    width: size + 'px',
                    height: size + 'px',
                    left: x + 'px',
                    top: y + 'px',
                    borderRadius: '50%',
                    backgroundColor: 'rgba(255, 255, 255, 0.6)',
                    pointerEvents: 'none',
                    transform: 'scale(0)',
                    zIndex: '1'
                });

                gsap.to(ripple, {
                    scale: 1,
                    opacity: 0,
                    duration: 0.6,
                    ease: "power2.out",
                    onComplete: () => ripple.remove()
                });
            });
            
            button.setAttribute('data-btn-animated', 'true');
        });
    }

    // Form Animations
    animateForms() {
        const formGroups = document.querySelectorAll('.mb-3:not([data-form-animated]), .form-group:not([data-form-animated])');
        
        if (formGroups.length > 0) {
            gsap.from(formGroups, {
                opacity: 0,
                y: 20,
                duration: 0.5,
                stagger: 0.1,
                ease: "power2.out",
                delay: 0.3,
                onComplete: () => {
                    formGroups.forEach(group => group.setAttribute('data-form-animated', 'true'));
                }
            });
        }

        // Input focus animations
        const inputs = document.querySelectorAll('input:not([data-input-animated]), textarea:not([data-input-animated]), select:not([data-input-animated])');
        inputs.forEach(input => {
            input.addEventListener('focus', () => {
                gsap.to(input, {
                    scale: 1.01,
                    duration: 0.2,
                    ease: "power2.out"
                });
            });

            input.addEventListener('blur', () => {
                gsap.to(input, {
                    scale: 1,
                    duration: 0.2,
                    ease: "power2.out"
                });
            });
            
            input.setAttribute('data-input-animated', 'true');
        });
    }

    // Scroll Animations
    setupScrollAnimations() {
        if (typeof ScrollTrigger === 'undefined') return;
        
        const animateOnScroll = document.querySelectorAll('[data-animate="scroll"]:not([data-scroll-animated])');
        
        animateOnScroll.forEach(element => {
            gsap.from(element, {
                y: 50,
                opacity: 0,
                duration: 0.8,
                ease: "power3.out",
                scrollTrigger: {
                    trigger: element,
                    start: "top 85%",
                    end: "bottom 15%",
                    toggleActions: "play none none reverse"
                }
            });
            
            element.setAttribute('data-scroll-animated', 'true');
        });
    }

    // Modal Animations
    animateModals() {
        const modals = document.querySelectorAll('.modal:not([data-modal-animated])');
        
        modals.forEach(modal => {
            modal.addEventListener('show.bs.modal', () => {
                const modalDialog = modal.querySelector('.modal-dialog');
                if (!modalDialog) return;

                gsap.set(modalDialog, { 
                    scale: 0.8, 
                    opacity: 0,
                    y: -30
                });
                
                gsap.to(modalDialog, {
                    scale: 1,
                    opacity: 1,
                    y: 0,
                    duration: 0.4,
                    ease: "back.out(1.2)"
                });
            });

            modal.addEventListener('hide.bs.modal', () => {
                const modalDialog = modal.querySelector('.modal-dialog');
                if (!modalDialog) return;

                gsap.to(modalDialog, {
                    scale: 0.8,
                    opacity: 0,
                    y: -30,
                    duration: 0.3,
                    ease: "power2.in"
                });
            });
            
            modal.setAttribute('data-modal-animated', 'true');
        });
    }

    // Notification Animations
    animateNotifications() {
        const alerts = document.querySelectorAll('.alert:not([data-alert-animated])');
        
        alerts.forEach(alert => {
            // Add close button if not present
            if (!alert.querySelector('.btn-close')) {
                const closeButton = document.createElement('button');
                closeButton.type = 'button';
                closeButton.className = 'btn-close';
                closeButton.setAttribute('data-bs-dismiss', 'alert');
                closeButton.setAttribute('aria-label', 'Close');
                alert.appendChild(closeButton);
            }
            
            // Initial animation
            gsap.from(alert, {
                y: -20,
                opacity: 0,
                duration: 0.4,
                ease: "power2.out"
            });

            // Add auto-hide for all alerts after 5 seconds (unless it's an error)
            const isErrorMessage = alert.classList.contains('alert-danger');
            if (!isErrorMessage) {
                setTimeout(() => {
                    gsap.to(alert, {
                        height: 0,
                        opacity: 0,
                        marginTop: 0,
                        marginBottom: 0,
                        paddingTop: 0,
                        paddingBottom: 0,
                        duration: 0.5,
                        ease: "power2.in",
                        onComplete: () => alert.remove()
                    });
                }, 5000);
            }
            
            alert.setAttribute('data-alert-animated', 'true');
        });
    }

    // Create and animate a notification message
    showNotification(message, type = 'info', container = null) {
        // Create alert element
        const alert = document.createElement('div');
        alert.className = `alert alert-${type} alert-dismissible fade show`;
        alert.setAttribute('role', 'alert');
        alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        
        // Find container or use default
        const targetContainer = container || document.querySelector('.main-content') || document.body;
        
        // Insert at the beginning of the container
        if (targetContainer.firstChild) {
            targetContainer.insertBefore(alert, targetContainer.firstChild);
        } else {
            targetContainer.appendChild(alert);
        }
        
        // Animate the new alert
        gsap.from(alert, {
            y: -20,
            opacity: 0,
            duration: 0.4,
            ease: "power2.out"
        });
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            gsap.to(alert, {
                height: 0,
                opacity: 0,
                marginTop: 0,
                marginBottom: 0,
                paddingTop: 0,
                paddingBottom: 0,
                duration: 0.5,
                ease: "power2.in",
                onComplete: () => alert.remove()
            });
        }, 5000);
        
        return alert;
    }

    // Dashboard specific animations
    animateDashboardStats() {
        const statCards = document.querySelectorAll('[data-stat-value]:not([data-stat-animated])');
        
        statCards.forEach(card => {
            const raw = card.dataset.statValue;
            const value = Number.parseInt(raw, 10);
            const element = card.querySelector('.stat-number');
            if (!element || Number.isNaN(value)) return;

            // Animate counter
            const counter = { val: 0 };
            gsap.to(counter, {
                val: value,
                duration: 1.2,
                ease: 'power2.out',
                delay: 0.2,
                onUpdate: () => {
                    element.textContent = Math.round(counter.val).toString();
                },
                onComplete: () => {
                    element.textContent = value.toString();
                }
            });
            
            card.setAttribute('data-stat-animated', 'true');
        });
    }

    // Page Transition Animation
    transitionToPage(targetUrl) {
        const pageContainer = document.querySelector('.page-container');
        if (!pageContainer) {
            window.location.href = targetUrl;
            return;
        }
        
        gsap.to(pageContainer, {
            opacity: 0,
            x: -30,
            duration: 0.3,
            ease: "power2.in",
            onComplete: () => {
                window.location.href = targetUrl;
            }
        });
    }

    // Loading Animation
    showLoadingAnimation(container) {
        const loader = document.createElement('div');
        loader.className = 'loading-animation';
        loader.innerHTML = `
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        `;
        
        container.appendChild(loader);
        
        gsap.from(loader, {
            scale: 0,
            opacity: 0,
            duration: 0.3,
            ease: "back.out(1.7)"
        });
        
        return loader;
    }

    hideLoadingAnimation(loader) {
        if (!loader) return;
        
        gsap.to(loader, {
            scale: 0,
            opacity: 0,
            duration: 0.3,
            ease: "power2.in",
            onComplete: () => loader.remove()
        });
    }

    // Refresh animations for dynamic content
    refreshAnimations() {
        if (!this.isInitialized || this.reducedMotion) return;
        
        this.animateCards();
        this.animateButtons();
        this.animateForms();
        this.animateNotifications();
        this.setupScrollAnimations();
        this.animateModals();
    }

    // Fallback to make all elements visible (for reduced motion or errors)
    makeAllElementsVisible() {
        const animatedElements = document.querySelectorAll(
            '.animate-fade-in, .animate-slide-up, .animate-slide-left, .animate-scale, [style*="opacity: 0"]'
        );
        
        animatedElements.forEach(el => {
            el.style.opacity = '1';
            el.style.transform = 'none';
            el.style.translate = 'none';
            el.style.rotate = 'none';
            el.style.scale = 'none';
        });
    }
}

// Initialize Animation Manager
const animationManager = new AnimationManager();

// Export for global access
window.AnimationManager = animationManager;
window.animationManager = animationManager;

// Auto-refresh animations when content changes (throttled for performance)
let refreshTimeout;
const observer = new MutationObserver((mutations) => {
    clearTimeout(refreshTimeout);
    refreshTimeout = setTimeout(() => {
        const hasNewNodes = mutations.some(mutation => 
            mutation.type === 'childList' && mutation.addedNodes.length > 0
        );
        if (hasNewNodes && window.animationManager) {
            window.animationManager.refreshAnimations();
        }
    }, 250);
});

// Start observing when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributeFilter: ['class']
    });
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    observer.disconnect();
    if (typeof ScrollTrigger !== 'undefined') {
        ScrollTrigger.killAll();
    }
    if (typeof gsap !== 'undefined') {
        gsap.globalTimeline.clear();
    }
});

// Performance monitoring
if (window.performance && window.performance.mark) {
    window.performance.mark('gsap-animations-loaded');
}

// Global utility function for troubleshooting
window.fixStuckElements = function() {
    if (window.animationManager) {
        window.animationManager.makeAllElementsVisible();
        console.log('Stuck elements fixed');
    }
};