</main>

<footer>
    <a href="contact.php">Επικοινωνία</a> ·
    <a href="terms.php">Όροι Χρήσης</a> ·
    <a href="privacy.php">Πολιτική απορρήτου</a> ·
    <a href="app.php">📱 Εφαρμογή κινητού</a>
    <br>Knowledge · κοινότητα φιλοσόφων &amp; ιστορικών
    <br><button type="button" id="install-app" class="small-button" hidden>📱 Εγκατάσταση εφαρμογής</button>
</footer>

<script>
// Android / Chrome / Edge: ο browser μάς επιτρέπει να δείξουμε δικό μας κουμπί «Εγκατάσταση»
(function () {
    let prompt = null;
    const button = document.getElementById('install-app');
    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        prompt = event;
        button.hidden = false;
    });
    button.addEventListener('click', function () {
        if (!prompt) { return; }
        prompt.prompt();
        prompt.userChoice.finally(function () { prompt = null; button.hidden = true; });
    });
})();
</script>

<button id="chat-toggle" type="button" title="Βοηθός">?</button>

<div id="chat-box" hidden>
    <div id="chat-header">Βοηθός Knowledge</div>
    <div id="chat-messages">
        <p class="bot">Γεια σου! Ρώτησέ με κάτι για το site, π.χ. «πώς ανεβάζω έγγραφο;»</p>
    </div>
    <form id="chat-form">
        <input type="text" id="chat-input" maxlength="300" placeholder="Γράψε την ερώτησή σου..." autocomplete="off">
        <button type="submit">Αποστολή</button>
    </form>
</div>

<script>
const chatToggle   = document.getElementById('chat-toggle');
const chatBox      = document.getElementById('chat-box');
const chatForm     = document.getElementById('chat-form');
const chatInput    = document.getElementById('chat-input');
const chatMessages = document.getElementById('chat-messages');

chatToggle.addEventListener('click', function () {
    chatBox.hidden = !chatBox.hidden;
    if (!chatBox.hidden) {
        chatInput.focus();
    }
});

function addChatMessage(text, who) {
    const p = document.createElement('p');
    p.className = who;
    p.textContent = text;
    chatMessages.appendChild(p);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

chatForm.addEventListener('submit', function (event) {
    event.preventDefault();
    const question = chatInput.value.trim();
    if (question === '') {
        return;
    }
    addChatMessage(question, 'user');
    chatInput.value = '';

    fetch('chatbot.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'message=' + encodeURIComponent(question)
    })
        .then(function (response) { return response.json(); })
        .then(function (data) { addChatMessage(data.answer, 'bot'); })
        .catch(function () { addChatMessage('Κάτι πήγε στραβά. Δοκίμασε ξανά.', 'bot'); });
});
</script>
</body>
</html>
