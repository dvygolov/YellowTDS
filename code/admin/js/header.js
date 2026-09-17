function typeText(text, element) {
    let index = 0;
    let isTyping = true;
    element.textContent = '';
    let animationRunning = true;
    
    function animate() {
        if (!animationRunning) return;
        
        if (isTyping) {
            if (index < text.length) {
                element.textContent += text.charAt(index);
                index++;
                setTimeout(animate, 100);
            } else {
                setTimeout(() => {
                    if (!animationRunning) return;
                    isTyping = false;
                    animate();
                }, 1000);
            }
        } else {
            if (index > 0) {
                element.textContent = text.substring(0, index - 1);
                index--;
                setTimeout(animate, 50);
            } else {
                setTimeout(() => {
                    if (!animationRunning) return;
                    isTyping = true;
                    animate();
                }, 500);
            }
        }
    }
    
    animate();
    
    // Return a cleanup function
    return () => {
        animationRunning = false;
        element.textContent = '';
    };
}

function getHeaderDateConfig() {
    const configNode = document.getElementById('headerDateConfig');
    if (!configNode) return null;

    try {
        return JSON.parse(configNode.textContent);
    } catch (error) {
        console.error('Failed to parse header date config', error);
        return null;
    }
}

function buildTimezoneFooter(instance, config) {
    if (!instance || !instance.calendarContainer || !config || !config.enabled) return;

    let footer = instance.calendarContainer.querySelector('.flatpickr-tz-footer');
    if (!footer) {
        footer = document.createElement('div');
        footer.className = 'flatpickr-tz-footer';
        footer.innerHTML = `
            <label class="flatpickr-tz-label" for="flatpickr-timezone-select">Timezone</label>
            <select id="flatpickr-timezone-select" class="flatpickr-tz-select"></select>
        `;
        instance.calendarContainer.appendChild(footer);
    }

    const select = footer.querySelector('.flatpickr-tz-select');
    const selectedTimezone = config.pendingTimezone || config.timezone;

    if (!select.dataset.initialized) {
        const options = config.options || [];
        options.forEach((option) => {
            const optionNode = document.createElement('option');
            optionNode.value = option.value;
            optionNode.textContent = option.label;
            select.appendChild(optionNode);
        });
        select.dataset.initialized = 'true';
    }

    select.value = selectedTimezone;
    select.disabled = !!config.savingTimezone;

    if (!select.dataset.bound) {
        select.addEventListener('change', async () => {
            await saveTimezoneSetting(select.value, config, select);
        });
        select.dataset.bound = 'true';
    }
}

async function saveTimezoneSetting(timezone, config, select) {
    if (!timezone || timezone === config.timezone || config.savingTimezone) {
        return;
    }

    config.savingTimezone = true;
    config.pendingTimezone = timezone;
    if (select) select.disabled = true;

    try {
        let response;
        if (config.scope === 'campaign' && config.campId) {
            response = await fetch(`campeditor.php?action=save&campId=${config.campId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ statistics: { timezone } }),
            });
        } else {
            response = await fetch('commonseditor.php?action=savetimezone', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({ timezone }).toString(),
            });
        }

        const result = await response.json();
        if (result.error) {
            throw new Error(result.result || 'Failed to save timezone');
        }

        window.location.reload();
    } catch (error) {
        alert('Error saving timezone: ' + error.message);
        config.pendingTimezone = config.timezone;
        if (select) {
            select.value = config.timezone;
            select.disabled = false;
        }
    } finally {
        config.savingTimezone = false;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const headerDateConfig = getHeaderDateConfig();
    if (headerDateConfig?.enabled && document.getElementById('litepicker')) {
        flatpickr('#litepicker', {
            dateFomat: 'DD.MM.YY',
            mode: 'range',
            onReady: function(selectedDates, dateStr, instance) {
                buildTimezoneFooter(instance, headerDateConfig);
            },
            onOpen: function(selectedDates, dateStr, instance) {
                buildTimezoneFooter(instance, headerDateConfig);
            },
            onMonthChange: function(selectedDates, dateStr, instance) {
                buildTimezoneFooter(instance, headerDateConfig);
            },
            onYearChange: function(selectedDates, dateStr, instance) {
                buildTimezoneFooter(instance, headerDateConfig);
            },
            onClose: function(selectedDates, dateStr, instance) {
                buildTimezoneFooter(instance, headerDateConfig);
                if (selectedDates.length < 2) return;
                update_datepicker_dates(selectedDates);
            }
        });
    }

    const updateBasesLink = document.getElementById('updateBases');
    const loadingAnimation = document.getElementById('loadingAnimation');
    const updateOverlay = document.getElementById('updateOverlay');
    const typingText = document.getElementById('typing-text');
    let typingCleanup = null;

    if (updateBasesLink) {
        updateBasesLink.addEventListener('click', async function(e) {
            e.preventDefault();
            loadingAnimation.style.display = 'inline';
            updateOverlay.style.display = 'flex';
            const rainCleanup = setupMatrixRain();
            typingCleanup = typeText('GEOBASES UPDATING...', typingText);

            try {
                const response = await fetch('../bases/update.php');
                const jsr = await response.json();
                if (!jsr.error) {
                    alert('Update SUCCESSFULL:\n' + jsr.result);
                    location.reload();
                } else {
                    alert('Error updating geobases:\n' + jsr.result);
                }
            } catch (error) {
                alert('Error updating geobases:\n' + error);
            } finally {
                rainCleanup();
                if (typingCleanup) typingCleanup();
                loadingAnimation.style.display = 'none';
                updateOverlay.style.display = 'none';
            }
        });
    }
});

async function sendAutoupdateRequest(action) {
    const response = await fetch('autoupdate.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=${action}`
    });
    return await response.json();
}

function update_datepicker_dates(selectedDates) {
    function formatDate(date) {
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = String(date.getFullYear()).slice(-2);
        return `${day}.${month}.${year}`;
    }
    let searchParams = new URLSearchParams(window.location.search);
    let d1 = formatDate(selectedDates[0]);
    let d2 = formatDate(selectedDates[1]);
    searchParams.set('startdate', d1);
    searchParams.set('enddate', d2);
    location.search = searchParams.toString();
}
