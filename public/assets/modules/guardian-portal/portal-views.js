'use strict';
// F8: shared renderers of the student surface and the guardian portal (results with GPA/GPAX, timetable day view + printable week view).
// Every interpolated value goes through SaanCore.esc. Callers own the click handling (data-act="day" / "print").
(() => {
    const esc = v => window.SaanCore.esc(v);
    const ATT = {present: 'มาเรียน', absent: 'ขาด', late: 'สาย', leave: 'ลา', sick: 'ป่วย'};
    const todayDay = () => (new Date().getDay() || 7);

    function gradeCell(c) {
        if (c.state === 'special') return `<b>${esc(c.special)}</b> <span class="gp-muted">${esc(c.special_label || '')}</span>`;
        if (c.state === 'graded') return `<b>${esc(c.grade)}</b>`;
        return '<span class="gp-muted">ยังไม่สมบูรณ์</span>';
    }

    function report(d, opts = {}) {
        if (!d.available) return '<p class="gp-card">โรงเรียนยังไม่เปิดใช้การแสดงผลการเรียน</p>';
        if (!d.terms.length) return '<p class="gp-card">ยังไม่มีผลการเรียนที่โรงเรียนประกาศ ผลจะแสดงที่นี่เมื่อโรงเรียนกดประกาศผลของภาคเรียนนั้น</p>';
        const gpax = d.gpax ? `<div class="gp-card gp-gpax"><span class="gp-muted">เกรดเฉลี่ยสะสม (GPAX)</span> <b class="gp-big">${esc(d.gpax.value)}</b> <span class="gp-muted">หน่วยกิตรวม ${esc(d.gpax.credits)}</span></div>` : '';
        return gpax + d.terms.map(t => {
            const rows = t.courses.map(c => `<tr><td data-label="วิชา">${esc(c.subject)}</td><td data-label="หน่วยกิต">${c.credit === null ? '-' : esc(c.credit)}</td><td data-label="ผลการเรียน">${gradeCell(c)}</td></tr>`).join('');
            const rank = t.rank && t.rank.position ? `<p>ลำดับที่ <b>${esc(t.rank.position)}</b> จาก ${esc(t.rank.of)} คน</p>` : '';
            const attrs = t.attributes.length
                ? '<h3>คุณลักษณะและการอ่าน คิดวิเคราะห์ เขียน</h3><ul class="gp-list">' + t.attributes.map(a => `<li class="gp-item gp-row"><span>${esc(a.kind_label)} ${esc(a.code)}</span><span class="gp-badge">${esc(a.level_label)}</span></li>`).join('') + '</ul>' : '';
            let pp6 = '';
            if (t.pp6) {
                const att = t.pp6.attendance ? Object.entries(t.pp6.attendance).map(([k, v]) => `${esc(ATT[k] || k)} ${esc(v)} วัน`).join(' · ') : '';
                pp6 = '<h3>ปพ.6 (ดูอย่างเดียว)</h3>' + (att ? `<p>เวลาเรียน: ${att}</p>` : '') + (t.pp6.remark ? `<p>หมายเหตุครูประจำชั้น: ${esc(t.pp6.remark)}</p>` : '');
            }
            const ack = opts.ack ? opts.ack(t) : '';
            return `<div class="gp-card"><h2>ปีการศึกษา ${esc(t.year)} ภาคเรียนที่ ${esc(t.term)}</h2>`
                + '<table class="gp-table"><thead><tr><th scope="col">วิชา</th><th scope="col">หน่วยกิต</th><th scope="col">ผลการเรียน</th></tr></thead><tbody>' + rows + '</tbody></table>'
                + `<p class="gp-gpa">เกรดเฉลี่ยภาคเรียน <b>${t.gpa === null ? '-' : esc(t.gpa)}</b> <span class="gp-muted">หน่วยกิตที่นับ ${esc(t.credits)}</span></p>${rank}${attrs}${pp6}${ack}</div>`;
        }).join('');
    }

    function timetable(d, day) {
        if (!d.available) return '<p class="gp-card">โรงเรียนยังไม่เปิดใช้ตารางเรียน</p>';
        if (!d.days.length) return '<p class="gp-card">ยังไม่มีตารางเรียนของห้องนี้ในภาคเรียนปัจจุบัน</p>';
        const sel = d.days.find(x => x.weekday === day) || d.days.find(x => x.weekday === todayDay()) || d.days[0];
        const tabs = '<nav class="gp-tabs gp-noprint" aria-label="เลือกวัน">' + d.days.map(x => `<button type="button" data-act="day" data-day="${esc(x.weekday)}" aria-current="${x.weekday === sel.weekday}">${esc(x.name)}</button>`).join('') + '</nav>';
        const dayView = `<div class="gp-dayview">${tabs}<ul class="gp-list">` + sel.lessons.map(l => `<li class="gp-item"><div class="gp-row"><b>คาบ ${esc(l.period)}</b><span class="gp-muted">${esc(l.starts_at)} - ${esc(l.ends_at)}</span></div><div>${esc(l.subject)}</div>${l.room ? `<div class="gp-muted">ห้อง ${esc(l.room)}</div>` : ''}</li>`).join('') + '</ul></div>';
        const cols = d.days;
        const cell = (c, p) => { const l = c.lessons.find(x => x.period === p.number); return `<td>${l ? esc(l.subject) + (l.room ? `<br><span class="gp-muted">${esc(l.room)}</span>` : '') : ''}</td>`; };
        const used = d.periods.filter(p => cols.some(c => c.lessons.some(l => l.period === p.number)));
        const week = '<div class="gp-weekview"><table class="gp-week"><thead><tr><th scope="col">คาบ</th>' + cols.map(c => `<th scope="col">${esc(c.name)}</th>`).join('') + '</tr></thead><tbody>'
            + used.map(p => `<tr><th scope="row">${esc(p.number)}<br><span class="gp-muted">${esc(p.starts_at)}-${esc(p.ends_at)}</span></th>${cols.map(c => cell(c, p)).join('')}</tr>`).join('') + '</tbody></table></div>';
        const head = `<p class="gp-muted">${esc(d.classroom || '')} · ปีการศึกษา ${esc(d.term.year)} ภาคเรียนที่ ${esc(d.term.term)}</p>`;
        return head + dayView + week + '<p class="gp-noprint"><button type="button" class="gp-btn" data-act="print">พิมพ์ตารางเรียนทั้งสัปดาห์</button></p>';
    }

    window.SaanPortal = {report, timetable};
})();
