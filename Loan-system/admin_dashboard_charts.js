(function () {
    'use strict';

    const trendDataElement = document.getElementById('dashboardCollectionTrendData');
    const statusDataElement = document.getElementById('dashboardLoanStatusData');
    if (!trendDataElement || !statusDataElement) return;

    let trendData;
    let statusData;
    try {
        trendData = JSON.parse(trendDataElement.textContent);
        statusData = JSON.parse(statusDataElement.textContent);
    } catch (error) {
        return;
    }

    const currencyColors = {
        PHP: '#273c5a',
        SGD: '#d6a51c'
    };
    const statusColors = {
        pending: '#f6c343',
        approved: '#273c5a',
        completed: '#198754',
        rejected: '#dc3545',
        other: '#8a97a8'
    };
    const statusOrder = ['pending', 'approved', 'completed', 'rejected', 'other'];

    function compactAmount(value, currency) {
        try {
            return currency + ' ' + new Intl.NumberFormat('en', {
                notation: 'compact',
                maximumFractionDigits: 1
            }).format(value);
        } catch (error) {
            return currency + ' ' + new Intl.NumberFormat('en', {
                maximumFractionDigits: 0
            }).format(value);
        }
    }

    function fitCanvas(canvas, height) {
        const width = Math.max(240, Math.floor(canvas.getBoundingClientRect().width));
        const ratio = Math.max(1, window.devicePixelRatio || 1);
        canvas.width = Math.floor(width * ratio);
        canvas.height = Math.floor(height * ratio);
        canvas.style.height = height + 'px';
        const context = canvas.getContext('2d');
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.clearRect(0, 0, width, height);
        return { context, width, height };
    }

    function drawCollectionChart(canvas) {
        const currency = canvas.dataset.currency;
        const values = Array.isArray(trendData[currency]) ? trendData[currency] : [];
        const labels = Array.isArray(trendData.labels) ? trendData.labels : [];
        if (!values.length) return;

        const { context: ctx, width, height } = fitCanvas(canvas, 224);
        const padding = { top: 17, right: 12, bottom: 34, left: 61 };
        const plotWidth = width - padding.left - padding.right;
        const plotHeight = height - padding.top - padding.bottom;
        const maxValue = Math.max(...values, 0);
        const magnitude = maxValue > 0 ? Math.pow(10, Math.floor(Math.log10(maxValue))) : 1;
        const scaled = maxValue / magnitude;
        const niceScale = scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10;
        const scaleMax = magnitude * niceScale;

        ctx.font = '11px system-ui, sans-serif';
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'right';
        ctx.strokeStyle = '#e8edf3';
        ctx.lineWidth = 1;
        ctx.fillStyle = '#7b8798';

        for (let step = 0; step <= 4; step++) {
            const fraction = step / 4;
            const y = padding.top + plotHeight * (1 - fraction);
            ctx.beginPath();
            ctx.moveTo(padding.left, y);
            ctx.lineTo(width - padding.right, y);
            ctx.stroke();
            ctx.fillText(compactAmount(scaleMax * fraction, currency), padding.left - 7, y);
        }

        const groupWidth = plotWidth / values.length;
        const barWidth = Math.min(30, groupWidth * 0.56);
        const color = currencyColors[currency] || '#273c5a';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';

        values.forEach((value, index) => {
            const boundedValue = Math.max(0, Number(value) || 0);
            const barHeight = (boundedValue / scaleMax) * plotHeight;
            const x = padding.left + groupWidth * index + (groupWidth - barWidth) / 2;
            const y = padding.top + plotHeight - barHeight;

            if (barHeight > 0) {
                ctx.fillStyle = color;
                if (typeof ctx.roundRect === 'function') {
                    ctx.beginPath();
                    ctx.roundRect(x, y, barWidth, barHeight, Math.min(6, barWidth / 2, barHeight / 2));
                    ctx.fill();
                } else {
                    ctx.fillRect(x, y, barWidth, barHeight);
                }
            }

            ctx.fillStyle = '#748196';
            ctx.fillText(String(labels[index] || '').split(' ')[0], x + barWidth / 2, height - 10);
        });

        if (maxValue === 0) {
            ctx.fillStyle = '#8290a3';
            ctx.font = '600 12px system-ui, sans-serif';
            ctx.fillText('No collections yet', width / 2, padding.top + plotHeight / 2);
        }

        canvas.title = labels.map((label, index) =>
            label + ': ' + currency + ' ' + new Intl.NumberFormat('en', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(Number(values[index]) || 0)
        ).join(' | ');
    }

    function drawLoanStatusChart() {
        const canvas = document.getElementById('loanStatusChart');
        if (!canvas) return;

        const { context: ctx, width, height } = fitCanvas(canvas, 216);
        const centerX = width / 2;
        const centerY = height / 2;
        const outerRadius = Math.min(width * 0.31, height * 0.42);
        const innerRadius = outerRadius * 0.68;
        const total = statusOrder.reduce((sum, status) => sum + (Number(statusData[status]) || 0), 0);

        if (total === 0) {
            ctx.beginPath();
            ctx.arc(centerX, centerY, outerRadius, 0, Math.PI * 2);
            ctx.arc(centerX, centerY, innerRadius, Math.PI * 2, 0, true);
            ctx.fillStyle = '#e8edf3';
            ctx.fill();
        } else {
            let angle = -Math.PI / 2;
            statusOrder.forEach(status => {
                const value = Number(statusData[status]) || 0;
                if (value <= 0) return;
                const nextAngle = angle + (value / total) * Math.PI * 2;
                ctx.beginPath();
                ctx.arc(centerX, centerY, outerRadius, angle, nextAngle);
                ctx.arc(centerX, centerY, innerRadius, nextAngle, angle, true);
                ctx.closePath();
                ctx.fillStyle = statusColors[status];
                ctx.fill();
                angle = nextAngle;
            });
        }

        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#203149';
        ctx.font = '700 24px system-ui, sans-serif';
        ctx.fillText(new Intl.NumberFormat('en').format(total), centerX, centerY - 5);
        ctx.fillStyle = '#78869a';
        ctx.font = '11px system-ui, sans-serif';
        ctx.fillText('applications', centerX, centerY + 17);
        canvas.title = statusOrder.map(status =>
            status.charAt(0).toUpperCase() + status.slice(1) + ': ' +
            new Intl.NumberFormat('en').format(Number(statusData[status]) || 0)
        ).join(' | ');
    }

    function renderCharts() {
        document.querySelectorAll('.collection-trend-chart').forEach(drawCollectionChart);
        drawLoanStatusChart();
    }

    let resizeTimer;
    window.addEventListener('resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(renderCharts, 120);
    });

    renderCharts();
})();
