// Employee dashboard client logic.
// This script renders and keeps the chat list, message pane and badges
// in sync with the employee + customer APIs, and optionally uses WebSockets
// for realtime updates (with a polling fallback).
const API = window.LC_EMPLOYEE_API;
const CURRENT_EMPLOYEE_ID = Number(window.LC_EMPLOYEE_ID || 0);
const IS_ADMIN_EMPLOYEE = !!window.LC_EMPLOYEE_IS_ADMIN;
 
// ----------------------------- HTTP helpers ---------------------------------
async function apiGet(params) {
  const url = `${API}?${new URLSearchParams(params)}`;
  try {
    const res = await fetch(url, { credentials: "same-origin" });
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }
    const contentType = res.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      throw new Error('API returned non-JSON response. Check server logs.');
    }
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || "Request failed");
    return data;
  } catch (err) {
    throw new Error(`API request failed: ${err.message}`);
  }
}

async function apiPost(action, body) {
  try {
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body || {}),
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }
    const contentType = res.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      throw new Error('API returned non-JSON response. Check server logs.');
    }
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || "Request failed");
    return data;
  } catch (err) {
    throw new Error(`API request failed: ${err.message}`);
  }
}

async function apiPostForm(action, formData) {
  try {
    const res = await fetch(`${API}?action=${encodeURIComponent(action)}`, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}: ${res.statusText}`);
    }
    const contentType = res.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      throw new Error('API returned non-JSON response. Check server logs.');
    }
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || "Request failed");
    return data;
  } catch (err) {
    throw new Error(`API request failed: ${err.message}`);
  }
}

// Cache frequently accessed DOM elements.
const els = {
  list: document.getElementById("chatList"),
  title: document.getElementById("chatTitle"),
  meta: document.getElementById("chatMeta"),
  messages: document.getElementById("messages"),
  globalBadge: document.getElementById("globalUnreadBadge"),
  takeBtn: document.getElementById("takeBtn"),
  closeBtn: document.getElementById("closeBtn"),
  reopenBtn: document.getElementById("reopenBtn"),
  deleteBtn: document.getElementById("deleteBtn"),
  sendForm: document.getElementById("sendForm"),
  msgInput: document.getElementById("msgInput"),
  fileBtn: document.getElementById("fileBtn"),
  fileInput: document.getElementById("fileInput"),
  sendBtn: document.getElementById("sendBtn"),
  chips: Array.from(document.querySelectorAll(".chip")),
  channelChips: Array.from(document.querySelectorAll(".channelChip")),
};

if (!els.fileBtn && els.sendForm && els.sendBtn) {
  const fileBtn = document.createElement("button");
  fileBtn.type = "button";
  fileBtn.id = "fileBtn";
  fileBtn.className = "btn primary";
  fileBtn.textContent = "+";
  fileBtn.title = "Attach file";
  fileBtn.setAttribute("aria-label", "Attach file");
  els.sendForm.insertBefore(fileBtn, els.sendBtn);
  els.fileBtn = fileBtn;
}

if (!els.fileInput && els.sendForm) {
  const fileInput = document.createElement("input");
  fileInput.type = "file";
  fileInput.id = "fileInput";
  fileInput.style.display = "none";
  els.sendForm.appendChild(fileInput);
  els.fileInput = fileInput;
}

if (els.fileBtn) {
  els.fileBtn.textContent = "+";
  els.fileBtn.classList.add("primary");
  els.fileBtn.title = "Attach file";
  els.fileBtn.setAttribute("aria-label", "Attach file");
}

let currentStatusFilter = "all";
let currentChannelFilter = "all";
let selectedChatId = null;
let selectedChat = null;
let lastLoadedMessageId = 0;
let pollTimer = null;
let unreadSinceId = 0;
let typingTimeoutId = null;
let isCurrentlyTyping = false;

// Formatting helpers + HTML escaping.
function fmtTime(iso) {
  try {
    return new Date(iso).toLocaleString();
  } catch {
    return iso || "";
  }
}

function escapeHtml(s) {
  const div = document.createElement("div");
  div.textContent = s ?? "";
  return div.innerHTML;
}

function formatSourceChannel(sourceChannel) {
  const value = (sourceChannel || "website").toString().toLowerCase();
  const map = {
    website: "Website",
    whatsapp: "WhatsApp",
    email: "Email",
    facebook: "Facebook",
    instagram: "Instagram",
    other: "Other",
  };
  return map[value] || "Website";
}

function applyChannelTheme(channel) {
  const value = (channel || "all").toString().toLowerCase();
  const theme = value === "all" || value === "website" ? "default" : value;
  document.body.setAttribute("data-channel-theme", theme);
}

function applyChannelChipAccess(allowedChannels) {
  const allowed = new Set((Array.isArray(allowedChannels) ? allowedChannels : []).map((c) => (c || "").toString().toLowerCase()));
  const hasAnyAllowed = Array.from(allowed).some((c) => c !== "all");
  const hasAllPermission = allowed.has("all");

  els.channelChips.forEach((chip) => {
    const channel = (chip.dataset.channel || "").toLowerCase();
    const canAccess = channel === "all" ? hasAllPermission : allowed.has(channel);
    chip.disabled = !canAccess;
    chip.classList.toggle("disabled", !canAccess);
  });

  const currentChannelIsAllowed = currentChannelFilter === "all" ? hasAllPermission : allowed.has(currentChannelFilter);
  if (!currentChannelIsAllowed) {
    const fallback = hasAnyAllowed ? (allowed.has("website") ? "website" : Array.from(allowed)[0]) : "all";
    currentChannelFilter = fallback;
  }

  applyChannelTheme(currentChannelFilter);

  els.channelChips.forEach((chip) => {
    chip.classList.toggle("active", (chip.dataset.channel || "").toLowerCase() === currentChannelFilter);
  });
}

// Render (or re-render) the chat list in the left sidebar.
function renderChatList(chats) {
  els.list.innerHTML = "";
  let totalBadge = 0;
  const filteredChats = chats.filter((c) => {
    if (currentChannelFilter === "all") return true;
    return ((c.source_channel || "website").toString().toLowerCase() === currentChannelFilter);
  });

  filteredChats.forEach((c) => {
    const canAccess = c.can_access !== false && Number(c.can_access) !== 0;
    const sourceChannel = ((c.source_channel || "website").toString().toLowerCase());
    const item = document.createElement("div");
    item.className = "chatItem"
      + (c.id === selectedChatId ? " active" : "")
      + (!canAccess ? " disabled" : "")
      + (currentChannelFilter === "all" ? ` in-all-view source-${sourceChannel}` : "");
    item.dataset.chatId = String(c.id);
    if (!canAccess) {
      item.title = "Channel access is disabled for this chat";
    }

    const name = c.customer_name || "Anonymous";
    const last = (c.last_message_body || "").toString().slice(0, 80);
    const tags = Array.isArray(c.tags) ? c.tags : [];
    const unread = Number(c.unread_customer_count) || 0;
    const isNew = !!c.is_new_chat;
    const source = formatSourceChannel(c.source_channel);
    if (canAccess) {
      if (isNew) totalBadge += 1;
      totalBadge += unread;
    }

    item.innerHTML = `
      <div class="row">
        <div><strong>#${c.id}</strong> — ${escapeHtml(name)}</div>
        <div class="rowRight">
          ${canAccess && isNew ? `<span class="newBadge">NEW</span>` : ``}
          ${canAccess && unread > 0 ? `<span class="unreadBadge">${unread}</span>` : ``}
          <div class="status ${c.status}">${escapeHtml(c.status)}</div>
        </div>
      </div>
      <div class="sub">${escapeHtml(last)}</div>
      <div class="chatSource">Source: ${escapeHtml(source)}</div>
      <div class="tags">
        ${tags.map((t) => `<span class="tag">${escapeHtml(t.name)}</span>`).join("")}
      </div>
    `;

    if (canAccess) {
      item.addEventListener("click", () => openChat(c.id));
    }
    els.list.appendChild(item);
  });

  if (els.globalBadge) {
    if (totalBadge > 0) {
      els.globalBadge.textContent = String(totalBadge);
      els.globalBadge.style.display = "inline-flex";
      document.title = `(${totalBadge}) Live Chat - Employee`;
    } else {
      els.globalBadge.style.display = "none";
      document.title = "Live Chat - Employee";
    }
  }
}

// Render the messages for the currently selected chat.
function renderMessages(messages) {
  els.messages.innerHTML = "";
  messages.forEach(addMessageToPane);
  scrollToBottom();
}

// Append a single message bubble to the chat pane.
function addMessageToPane(m) {
  const div = document.createElement("div");
  const cls =
    m.sender_type === "employee"
      ? "msg me"
      : m.sender_type === "system"
        ? "msg system"
        : "msg";
  div.className = cls;

  const isCustomer = m.sender_type === "customer";
  const isUnreadCustomer = isCustomer && (Number(m.id) || 0) > unreadSinceId;
  // For employees: a message is "read" by customer when `customer_read_at` is set.
  const isRead = m.customer_read_at !== null;
  
  // Determine sender name
  let senderName = m.sender_type;
  if (m.sender_type === 'system') {
    if (m.sender_employee_id) {
      // Try to get employee display name from cache
      senderName = window.LC_EMPLOYEE_NAMES?.[m.sender_employee_id] || 'System';
    } else {
      senderName = 'Corenio Bot';
    }
  } else if (m.sender_type === 'employee') {
    // On the employee dashboard show the employee's username if available,
    // otherwise fall back to display name and finally a generic label.
    senderName = m.employee_username || m.employee_display_name || 'Employee';
  } else if (m.sender_type === 'customer') {
    // Show the customer name from the current chat
    senderName = selectedChat?.customer_name || 'Customer';
  }

  const fileHtml = m.file_id
    ? renderFileMessageHtml(m)
    : `<div class="msgBody">${escapeHtml(m.body)}</div>`;

  div.innerHTML = `
    <div class="msgLine">
      ${fileHtml}
      ${isCustomer ? `<span class="msgDot" title="Customer message"></span>` : ``}
    </div>
    <div class="msgMeta">
      ${escapeHtml(senderName)} • ${escapeHtml(fmtTime(m.created_at))}
      <span class="msgNotify ${isUnreadCustomer ? "show" : ""}">NEW</span>
      ${!isCustomer && isRead ? `<span class="msgRead" title="Customer read this">✓</span>` : ``}
    </div>
  `;
  els.messages.appendChild(div);
  lastLoadedMessageId = Math.max(lastLoadedMessageId, Number(m.id) || 0);
}

function scrollToBottom() {
  els.messages.scrollTop = els.messages.scrollHeight;
}

function getFileDownloadUrl(fileId) {
  return `${API}?action=download_file&file_id=${encodeURIComponent(String(fileId))}`;
}

function getFileInlineUrl(fileId) {
  return `${API}?action=download_file&file_id=${encodeURIComponent(String(fileId))}&inline=1`;
}

function isImageMime(mime) {
  return typeof mime === "string" && mime.toLowerCase().startsWith("image/");
}

function renderFileMessageHtml(m) {
  const fileName = escapeHtml(m.file_name || 'Download file');
  const sizeLabel = formatBytes(m.file_size_bytes);
  const downloadUrl = escapeHtml(getFileDownloadUrl(m.file_id));
  if (isImageMime(m.file_mime)) {
    const imgUrl = escapeHtml(getFileInlineUrl(m.file_id));
    return `
      <div class="msgBody fileWrap">
        <a href="${downloadUrl}" target="_blank" rel="noopener noreferrer" class="fileLink">${fileName}</a>
        <div class="fileSize">${sizeLabel}</div>
        <a href="${imgUrl}" target="_blank" rel="noopener noreferrer">
          <img class="msgFileImage" src="${imgUrl}" alt="${fileName}" loading="lazy" />
        </a>
      </div>
    `;
  }
  return `<div class="msgBody fileWrap"><a href="${downloadUrl}" target="_blank" rel="noopener noreferrer" class="fileLink">${fileName}</a> <span class="fileSize">(${sizeLabel})</span></div>`;
}

function formatBytes(value) {
  const n = Number(value) || 0;
  if (n <= 0) return "0 B";
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  return `${(n / (1024 * 1024)).toFixed(1)} MB`;
}

// Enable/disable action buttons + composer based on chat state.
function updateControls() {
  const hasChat = !!selectedChat;
  els.takeBtn.disabled = !hasChat || selectedChat.status !== "open";
  els.closeBtn.disabled = !hasChat || selectedChat.status === "closed" || selectedChat.assigned_employee_id == null;
  els.reopenBtn.disabled = !hasChat || selectedChat.status !== "closed" || selectedChat.assigned_employee_id == null;
  els.deleteBtn.disabled = !hasChat || selectedChat.status !== "closed";

  const assignedEmployeeId = selectedChat && selectedChat.assigned_employee_id != null
    ? Number(selectedChat.assigned_employee_id)
    : null;
  const hasKnownEmployeeId = Number.isInteger(CURRENT_EMPLOYEE_ID) && CURRENT_EMPLOYEE_ID > 0;
  const canSendByAssignment = assignedEmployeeId != null && (!hasKnownEmployeeId || assignedEmployeeId === CURRENT_EMPLOYEE_ID);
  const canSend = hasChat && selectedChat.status !== "closed" && (IS_ADMIN_EMPLOYEE || canSendByAssignment);
  els.msgInput.disabled = !canSend;
  els.sendBtn.disabled = !canSend;
  if (els.fileBtn) els.fileBtn.disabled = !canSend;
  if (els.fileInput) els.fileInput.disabled = !canSend;
}

// Fetch latest chats for the current filter and update the list + badge.
async function refreshList() {
  const data = await apiGet({ action: "list_chats", status: currentStatusFilter, channel: currentChannelFilter });
  applyChannelChipAccess(data.allowed_channels || []);
  renderChatList(data.chats || []);
}

// Load a specific chat into the right-hand pane.
async function openChat(chatId) {
  selectedChatId = chatId;
  lastLoadedMessageId = 0;
  unreadSinceId = 0;
  const data = await apiGet({ action: "get_chat", chat_id: chatId });
  selectedChat = data.chat;
  unreadSinceId = Number(data.unread_since_id) || 0;

  els.title.textContent = `#${selectedChat.id} — ${selectedChat.customer_name || "Anonymous"}`;
  const tagNames = (selectedChat.tags || []).map((t) => t.name).join(", ");
  const source = formatSourceChannel(selectedChat.source_channel);
  els.meta.textContent = `${selectedChat.status} • Source: ${source} • Tags: ${tagNames || "—"} • Updated: ${fmtTime(selectedChat.updated_at)}`;

  renderMessages(data.messages || []);
  updateControls();
  startPollingMessages();
  
  // Mark all customer messages as read by employee
  try {
    await apiPost("mark_read", { chat_id: chatId });
  } catch {
    // ignore
  }
  
  await refreshList();
}

// Start polling for new messages when WS is not connected.
function startPollingMessages() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    if (!selectedChatId || !selectedChat) return;
    try {
      const data = await apiGet({ action: "poll", chat_id: selectedChatId, since_id: lastLoadedMessageId });
      if (data.chat && selectedChat) {
        selectedChat.status = data.chat.status;
        updateControls();
      }
      (data.messages || []).forEach(addMessageToPane);
      
      // Poll typing status
      await checkTypingStatus();
    } catch {
      // ignore
    }
  }, 2000);
}

// Check if customer is typing in current chat.
async function checkTypingStatus() {
  if (!selectedChatId) return;
  try {
    const data = await apiGet({ action: "get_typing_status", chat_id: selectedChatId });
    const typingDiv = document.getElementById("typingIndicator");
    if (data.typing && data.typing.employee_typing && typingDiv) {
      typingDiv.style.display = "block";
    } else if (typingDiv) {
      typingDiv.style.display = "none";
    }
  } catch {
    // ignore
  }
}

// Take ownership of the currently selected chat.
async function takeSelected() {
  if (!selectedChatId) return;
  const data = await apiPost("take_chat", { chat_id: selectedChatId });
  await openChat(selectedChatId);
  return data;
}

// Close the selected chat.
async function closeSelected() {
  if (!selectedChatId) return;
  await apiPost("close_chat", { chat_id: selectedChatId });
  await openChat(selectedChatId);
}

// Re-open the selected chat.
async function reopenSelected() {
  if (!selectedChatId) return;
  await apiPost("reopen_chat", { chat_id: selectedChatId });
  await openChat(selectedChatId);
}

// Delete the selected chat (only for closed chats).
async function deleteSelected() {
  if (!selectedChatId) return;
  if (!confirm("Are you sure you want to delete this chat? This cannot be undone.")) return;
  await apiPost("delete_chat", { chat_id: selectedChatId });
  selectedChatId = null;
  selectedChat = null;
  renderChatList([]);
  await refreshList();
}

// Send a message as the current employee.
async function sendMessage(body) {
  if (!selectedChatId) return;
  isCurrentlyTyping = false;
  if (typingTimeoutId) clearTimeout(typingTimeoutId);
  
  try {
    await apiPost("set_typing", { chat_id: selectedChatId, is_typing: false });
  } catch {
    // ignore
  }
  
  const data = await apiPost("send", { chat_id: selectedChatId, body });
  addMessageToPane(data.message);
  scrollToBottom();
  await refreshList();
}

async function sendFile(file) {
  if (!selectedChatId || !file) return;
  const form = new FormData();
  form.append("chat_id", String(selectedChatId));
  form.append("file", file);
  const data = await apiPostForm("send_file", form);
  addMessageToPane(data.message);
  scrollToBottom();
  await refreshList();
}

// Wire up UI events for buttons, filters and the composer.
els.takeBtn.addEventListener("click", () => takeSelected().catch(alert));
els.closeBtn.addEventListener("click", () => closeSelected().catch(alert));
els.reopenBtn.addEventListener("click", () => reopenSelected().catch(alert));
els.deleteBtn.addEventListener("click", () => deleteSelected().catch(alert));

els.sendForm.addEventListener("submit", (e) => {
  e.preventDefault();
  const body = els.msgInput.value.trim();
  if (!body) return;
  els.msgInput.value = "";
  sendMessage(body).catch(alert);
});

if (els.fileBtn && els.fileInput) {
  els.fileBtn.addEventListener("click", () => {
    if (els.fileInput.disabled) return;
    els.fileInput.click();
  });

  els.fileInput.addEventListener("change", async () => {
    const file = els.fileInput.files && els.fileInput.files[0] ? els.fileInput.files[0] : null;
    if (!file) return;
    try {
      await sendFile(file);
    } catch (err) {
      alert(err?.message || "Failed to upload file");
    } finally {
      els.fileInput.value = "";
    }
  });
}

// Monitor typing in message input
els.msgInput.addEventListener("input", async () => {
  if (!selectedChatId) return;
  
  // Mark as typing
  if (!isCurrentlyTyping) {
    isCurrentlyTyping = true;
    try {
      await apiPost("set_typing", { chat_id: selectedChatId, is_typing: true });
    } catch {
      // ignore
    }
  }
  
  // Reset timeout for stopping typing
  if (typingTimeoutId) clearTimeout(typingTimeoutId);
  typingTimeoutId = setTimeout(async () => {
    isCurrentlyTyping = false;
    try {
      await apiPost("set_typing", { chat_id: selectedChatId, is_typing: false });
    } catch {
      // ignore
    }
  }, 3000); // Stop typing indicator after 3 seconds of no input
});

els.chips.forEach((chip) => {
  chip.addEventListener("click", async () => {
    els.chips.forEach((c) => c.classList.remove("active"));
    chip.classList.add("active");
    currentStatusFilter = chip.dataset.status || "all";
    await refreshList().catch(alert);
  });
});

els.channelChips.forEach((chip) => {
  chip.addEventListener("click", async () => {
    if (chip.disabled) return;
    els.channelChips.forEach((c) => c.classList.remove("active"));
    chip.classList.add("active");
    currentChannelFilter = (chip.dataset.channel || "all").toLowerCase();
    applyChannelTheme(currentChannelFilter);
    await refreshList().catch(alert);
  });
});

// Initial boot: start list refresh loop.
async function start() {
  await refreshList();
  setInterval(() => {
    refreshList().catch(() => {});
  }, 2500);
}

start().catch(alert);

