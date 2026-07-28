<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Samchat — fast, secure real-time messaging. Chat with friends and groups from your browser.">
    <title>Samchat</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@24.5.0/build/css/intlTelInput.css">

    @vite(['resources/css/chat.css', 'resources/js/chat.js'])
    <script type="module" src="https://cdn.jsdelivr.net/npm/emoji-picker-element@1.18.1/index.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@24.5.0/build/js/intlTelInput.min.js"></script>
</head>
<body>

<div id="app-container">
    
    <!-- Sidebar / Inbox -->
    <div class="sidebar">
        @php
            $user = auth()->user();
            $displayName = $user->first_name ? trim($user->first_name . ' ' . $user->last_name) : $user->username;
            $avatarUrl = $user->photo_url ?: "https://ui-avatars.com/api/?name=" . urlencode($displayName) . "&background=FF5722&color=fff";
        @endphp
        <div class="sidebar-header">
            <img src="{{ $avatarUrl }}" class="profile-pic" alt="Profile" id="my-profile-pic" title="Edit Profile">
            
            <div class="sidebar-header-icons">
                <div class="icon-action" id="btn-theme-toggle">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                </div>
                <div class="icon-action" id="btn-status">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 20.664a9.163 9.163 0 0 1-6.521-2.702.977.977 0 0 1 1.381-1.381 7.269 7.269 0 0 0 10.024.244.977.977 0 0 1 1.313 1.445A9.192 9.192 0 0 1 12 20.664zm7.965-6.112a.977.977 0 0 1-.944-1.229 7.26 7.26 0 0 0-4.8-8.804.977.977 0 0 1 .594-1.86 9.212 9.212 0 0 1 6.092 11.169.976.976 0 0 1-.942.724zm-16.025-.39a.977.977 0 0 1-.953-.769 9.21 9.21 0 0 1 6.626-10.86.975.975 0 1 1 .52 1.882l-.015.004a7.259 7.259 0 0 0-5.223 8.558.978.978 0 0 1-.955 1.185z"></path></svg>
                </div>
                <div class="icon-action" id="btn-calls">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M20.01 15.38c-1.23 0-2.42-.2-3.53-.56-.35-.12-.74-.03-1.01.24l-1.57 1.97c-2.83-1.35-5.48-3.9-6.89-6.83l1.95-1.66c.27-.28.35-.67.24-1.02-.37-1.11-.56-2.3-.56-3.53 0-.54-.45-.99-.99-.99H4.19C3.65 3 3 3.24 3 3.99 3 13.28 10.73 21 20.03 21c.75 0 1-.65 1-1.19v-3.44c0-.54-.45-.99-.99-.99z"></path></svg>
                </div>
                <div class="icon-action" id="btn-open-email" title="Email">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"></path></svg>
                    <span id="email-unread-badge" class="icon-badge" style="display: none;"></span>
                </div>
                <div class="icon-action" id="btn-new-chat">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19.005 3.175H4.674C3.642 3.175 3 3.789 3 4.821V21.02l3.544-3.514h12.461c1.033 0 2.064-1.06 2.064-2.093V4.821c-.001-1.032-1.032-1.646-2.064-1.646zm-4.989 9.869H7.041V11.1h6.975v1.944zm3-4H7.041V7.1h9.975v1.944z"></path></svg>
                </div>

                <div class="icon-action" id="btn-sidebar-menu">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 7a2 2 0 1 0-.001-4.001A2 2 0 0 0 12 7zm0 2a2 2 0 1 0-.001 3.999A2 2 0 0 0 12 9zm0 6a2 2 0 1 0-.001 3.999A2 2 0 0 0 12 15z"></path></svg>
                </div>
            </div>
            <!-- Sidebar Dropdown — deliberately NOT nested inside .sidebar-header-icons:
                 that container has overflow-x: auto (so the icon row can scroll on
                 narrow screens), which per the CSS overflow spec computes overflow-y
                 to auto too, clipping this absolutely-positioned dropdown so it never
                 became visible when opened. .sidebar-header (this element's parent)
                 has no overflow set, so it's a sibling here instead, anchored via
                 .sidebar-header's own position: relative. -->
            <div id="sidebar-dropdown" class="dropdown-menu">
                <div class="dropdown-item" id="btn-new-group">New group</div>
                <div class="dropdown-item" id="btn-open-meetings">Meetings</div>
                <div class="dropdown-item" onclick="event.stopPropagation(); openProfileModal(); document.getElementById('sidebar-dropdown').classList.remove('active');">Profile</div>
                <div class="dropdown-item" onclick="event.stopPropagation(); openSettingsModal(); document.getElementById('sidebar-dropdown').classList.remove('active');">Settings</div>
                <div class="dropdown-item" id="btn-open-payments">Payments</div>
                <div class="dropdown-item" onclick="event.stopPropagation(); document.getElementById('logout-form').submit();">Log out</div>
            </div>
        </div>
        
        <div class="search-container">
            <div class="search-input-wrapper">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="var(--text-muted)" style="margin-right: 8px; flex-shrink: 0;"><path d="M15.009 13.805h-.636l-.22-.219a5.184 5.184 0 0 0 1.256-3.386 5.207 5.207 0 1 0-5.207 5.208 5.183 5.183 0 0 0 3.385-1.255l.221.22v.635l4.004 3.999 1.194-1.195-3.997-4.007zm-4.808 0a3.605 3.605 0 1 1 0-7.21 3.605 3.605 0 0 1 0 7.21z"></path></svg>
                <input type="text" id="search-input" class="search-input" placeholder="Search or start new chat">
            </div>
        </div>

        <div class="sidebar-filters">
            <button class="filter-pill active" data-filter="all">All</button>
            <button class="filter-pill" data-filter="unread">Unread</button>
            <button class="filter-pill" data-filter="groups">Groups</button>
        </div>

        <div class="chat-list" id="chat-list">
            <!-- Skeleton loading -->
            <div class="skeleton-chat-item">
                <div class="skeleton skeleton-avatar"></div>
                <div class="skeleton-lines">
                    <div class="skeleton skeleton-line short"></div>
                    <div class="skeleton skeleton-line long"></div>
                </div>
            </div>
            <div class="skeleton-chat-item">
                <div class="skeleton skeleton-avatar"></div>
                <div class="skeleton-lines">
                    <div class="skeleton skeleton-line short"></div>
                    <div class="skeleton skeleton-line long"></div>
                </div>
            </div>
            <div class="skeleton-chat-item">
                <div class="skeleton skeleton-avatar"></div>
                <div class="skeleton-lines">
                    <div class="skeleton skeleton-line short"></div>
                    <div class="skeleton skeleton-line long"></div>
                </div>
            </div>
        </div>

        <div class="chat-list" id="search-results-list" style="display: none;"></div>
        
        <!-- New Chat Overlay -->
        <div id="new-chat-panel" class="overlay-panel">
            <div class="overlay-header">
                <svg id="btn-close-new-chat" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
                <span>New chat</span>
            </div>
            
            <div class="overlay-search">
                <input type="text" id="new-chat-search" placeholder="Search contacts">
            </div>
            
            <div class="chat-list" id="new-chat-contact-list">
                <!-- Contacts will be populated here -->
            </div>
        </div>

        <!-- New Group Overlay -->
        <div id="new-group-panel" class="overlay-panel z-10">
            <div class="overlay-header">
                <svg id="btn-close-new-group" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
                <span>Add group participants</span>
            </div>
            
            <div class="overlay-search">
                <input type="text" id="new-group-search" placeholder="Search contacts">
            </div>
            
            <div class="chat-list" id="new-group-contact-list">
                <!-- Contacts will be populated here -->
            </div>
            
            <!-- Floating Next Button -->
            <div id="btn-new-group-next" class="fab-button">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 4l1.4 1.4L7.8 11H20v2H7.8l5.6 5.6L12 20l-8-8 8-8z" transform="rotate(180 12 12)"></path></svg>
            </div>
        </div>
        
        <!-- Group Name Step Overlay -->
        <div id="new-group-name-panel" class="overlay-panel z-11">
            <div class="overlay-header">
                <svg id="btn-close-group-name" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
                <span>New group</span>
            </div>
            
            <div class="group-name-content">
                <div class="group-photo-placeholder">
                    <svg viewBox="0 0 24 24" width="48" height="48" fill="currentColor"><path d="M21.2 5.5h-3.9l-1.3-1.4c-.4-.5-1-.8-1.7-.8h-4.6c-.6 0-1.2.3-1.6.8L6.8 5.5H2.8C1.3 5.5 0 6.8 0 8.3v10.5C0 20.2 1.3 21.5 2.8 21.5h18.4c1.5 0 2.8-1.3 2.8-2.8V8.3c0-1.5-1.2-2.8-2.8-2.8zm-9.2 12.3c-3 0-5.5-2.5-5.5-5.5s2.5-5.5 5.5-5.5 5.5 2.5 5.5 5.5-2.5 5.5-5.5 5.5zm0-9c-1.9 0-3.5 1.6-3.5 3.5s1.6 3.5 3.5 3.5 3.5-1.6 3.5-3.5-1.6-3.5-3.5-3.5z"></path></svg>
                </div>
                <input type="text" id="new-group-name-input" class="group-name-input" placeholder="Group subject">
            </div>
            
            <div id="btn-create-group-submit" class="fab-button visible">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"></path></svg>
            </div>
        </div>
        
        <!-- Calls Overlay -->
        <div id="calls-panel" class="overlay-panel">
            <div class="overlay-header">
                <svg id="btn-close-calls" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
                <span>Call Logs</span>
            </div>
            
            <div class="overlay-search" style="justify-content: flex-end; padding: 10px;">
                <button id="btn-clear-call-logs" class="btn-outline danger" style="padding: 5px 15px; font-size: 14px;">Clear Logs</button>
            </div>
            
            <div class="chat-list" id="call-logs-list">
                <!-- Call logs will be populated here -->
                <div class="loading-text">Loading call logs...</div>
            </div>
        </div>

        <!-- Meetings Overlay -->
        <div id="meetings-panel" class="overlay-panel">
            <div class="overlay-header">
                <svg id="btn-close-meetings" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
                <span>Meetings</span>
            </div>
            <div class="overlay-search" style="justify-content: flex-end; padding: 10px;">
                <button id="btn-schedule-meeting" class="btn-outline" style="padding: 5px 15px; font-size: 14px;">+ Schedule</button>
            </div>
            <div class="chat-list" id="meetings-list">
                <div class="loading-text">Loading meetings...</div>
            </div>
        </div>

        <!-- Schedule Meeting Overlay -->
        <div id="schedule-meeting-panel" class="overlay-panel z-11">
            <div class="overlay-header">
                <svg id="btn-close-schedule-meeting" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
                <span>Schedule meeting</span>
            </div>
            <div style="padding: 15px; overflow-y: auto;">
                <input type="text" id="meeting-title-input" class="profile-form-input" placeholder="Title" style="width: 100%; margin-bottom: 10px;">
                <textarea id="meeting-description-input" class="profile-form-input" placeholder="Description (optional)" style="width: 100%; min-height: 60px; margin-bottom: 10px;"></textarea>
                <div class="meeting-datetime-row">
                    <input type="date" id="meeting-date-input" class="profile-form-input">
                    <input type="time" id="meeting-time-input" class="profile-form-input">
                </div>
                <select id="meeting-duration-input" class="profile-form-input" style="width: 100%; margin-bottom: 10px;">
                    <option value="15">15 minutes</option>
                    <option value="30" selected>30 minutes</option>
                    <option value="45">45 minutes</option>
                    <option value="60">60 minutes</option>
                    <option value="90">90 minutes</option>
                    <option value="120">120 minutes</option>
                </select>
                <select id="meeting-call-type-input" class="profile-form-input" style="width: 100%;">
                    <option value="video" selected>Video call</option>
                    <option value="audio">Audio call</option>
                </select>
                <div style="margin-top:15px; font-weight:600; color: var(--text-primary);">Invite participants</div>
            </div>
            <div class="chat-list" id="meeting-invite-contact-list" style="flex:1; overflow-y:auto;"></div>
            <div id="btn-submit-schedule-meeting" class="fab-button visible">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"></path></svg>
            </div>
        </div>

        <!-- Email Accounts Overlay -->
        <div id="email-accounts-panel" class="overlay-panel">
            <div class="overlay-header">
                <svg id="btn-close-email-accounts" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
                <span>Email</span>
            </div>
            <div class="overlay-search" style="justify-content: flex-end; padding: 10px;">
                <button id="btn-add-email-account" class="btn-outline" style="padding: 5px 15px; font-size: 14px;">+ Link account</button>
            </div>
            <div class="chat-list" id="email-accounts-list">
                <div class="loading-text">Loading email accounts...</div>
            </div>
        </div>

        <!-- Connect Email Overlay -->
        <div id="connect-email-panel" class="overlay-panel z-11">
            <div class="overlay-header">
                <svg id="btn-close-connect-email" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
                <span>Link an email account</span>
            </div>
            <div style="padding: 15px; overflow-y: auto;">
                <select id="email-provider-input" class="profile-form-input" style="width: 100%; margin-bottom: 10px;">
                    <option value="gmail" selected>Gmail</option>
                    <option value="yahoo">Yahoo Mail</option>
                    <option value="custom">Custom (IMAP/SMTP)</option>
                </select>
                <input type="email" id="email-address-input" class="profile-form-input" placeholder="Email address" style="width: 100%; margin-bottom: 10px;">
                <input type="password" id="email-app-password-input" class="profile-form-input" placeholder="App password" style="width: 100%; margin-bottom: 10px;">
                <div id="email-preset-help">
                    <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 8px;">
                        This is NOT your normal email password. Generate an app password in your account's security settings and paste it here.
                    </div>
                    <a id="email-app-password-help-link" href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener" style="font-size: 13px; color: var(--accent-color);">Generate an app password for Gmail</a>
                </div>
                <div id="email-custom-intro" style="display: none;">
                    <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 8px;">
                        We auto-detect your mail server settings from the email address. Tap below to review or change them before connecting.
                    </div>
                    <button type="button" id="btn-toggle-email-custom-fields" class="btn-outline" style="padding: 6px 14px; font-size: 13px;">Edit IMAP/SMTP settings</button>
                </div>
                <div id="email-custom-fields" style="display: none;">
                    <div style="font-weight: 600; color: var(--text-primary); margin: 12px 0 8px;">Incoming mail (IMAP)</div>
                    <div style="display: flex; gap: 8px; margin-bottom: 10px;">
                        <input type="text" id="email-imap-host-input" class="profile-form-input" placeholder="IMAP host" style="flex: 3;">
                        <input type="number" id="email-imap-port-input" class="profile-form-input" placeholder="Port" style="flex: 1;">
                    </div>
                    <select id="email-imap-encryption-input" class="profile-form-input" style="width: 100%; margin-bottom: 10px;">
                        <option value="ssl" selected>SSL</option>
                        <option value="tls">TLS</option>
                        <option value="starttls">STARTTLS</option>
                        <option value="none">None</option>
                    </select>
                    <div style="font-weight: 600; color: var(--text-primary); margin: 12px 0 8px;">Outgoing mail (SMTP)</div>
                    <div style="display: flex; gap: 8px; margin-bottom: 10px;">
                        <input type="text" id="email-smtp-host-input" class="profile-form-input" placeholder="SMTP host" style="flex: 3;">
                        <input type="number" id="email-smtp-port-input" class="profile-form-input" placeholder="Port" style="flex: 1;">
                    </div>
                    <select id="email-smtp-encryption-input" class="profile-form-input" style="width: 100%;">
                        <option value="ssl" selected>SSL</option>
                        <option value="tls">TLS / STARTTLS</option>
                        <option value="none">None</option>
                    </select>
                </div>
                <div id="connect-email-error" style="color: #ef4444; font-size: 13px; margin-top: 10px; display: none;"></div>
                <button id="btn-submit-connect-email" class="btn-outline" style="width: 100%; margin-top: 20px; padding: 10px;">Connect</button>
            </div>
        </div>

        <!-- Email Inbox Overlay -->
        <div id="email-inbox-panel" class="overlay-panel z-11">
            <div class="overlay-header">
                <svg id="btn-close-email-inbox" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
                <span id="email-inbox-title">Inbox</span>
                <div id="btn-refresh-email-inbox" class="icon-action" style="margin-left: auto;" title="Check for new mail">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M17.65 6.35A7.958 7.958 0 0 0 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08a5.99 5.99 0 0 1-5.65 4c-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"></path></svg>
                </div>
            </div>
            <div class="chat-list" id="email-inbox-list" style="flex:1; overflow-y:auto;">
                <div class="loading-text">Loading emails...</div>
            </div>
            <div id="btn-compose-email" class="fab-button visible">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34a.9959.9959 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"></path></svg>
            </div>
        </div>

        <!-- Email Detail Overlay -->
        <div id="email-detail-panel" class="overlay-panel z-12">
            <div class="overlay-header">
                <svg id="btn-close-email-detail" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
                <span>Email</span>
                <div id="btn-reply-email" class="icon-action" style="margin-left: auto; display: none;" title="Reply">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"></path></svg>
                </div>
                <div id="btn-reply-all-email" class="icon-action" style="display: none;" title="Reply all">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M7 8V5l-7 7 7 7v-3.1c.3 0 4.7-.1 7 1.1-1-3-3-6.7-7-9zM12 8V5l-7 7 7 7v-3.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z" transform="translate(3)"></path></svg>
                </div>
            </div>
            <div id="email-detail-content" style="padding: 20px; overflow-y: auto;"></div>
        </div>

        <!-- Compose Email Overlay -->
        <div id="compose-email-panel" class="overlay-panel z-13">
            <div class="overlay-header">
                <svg id="btn-close-compose-email" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
                <span id="compose-email-title">New email</span>
            </div>
            <div style="padding: 15px; overflow-y: auto;">
                <div id="compose-email-from" style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;"></div>
                <div id="compose-reply-mode-row" style="display: none; gap: 8px; margin-bottom: 10px;">
                    <button type="button" id="btn-reply-mode-single" class="btn-outline" style="padding: 5px 15px; font-size: 13px;">Reply</button>
                    <button type="button" id="btn-reply-mode-all" class="btn-outline" style="padding: 5px 15px; font-size: 13px;">Reply all</button>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                    <input type="email" id="compose-email-to" class="profile-form-input" placeholder="To (separate multiple with commas)" style="flex: 1;">
                    <button type="button" id="btn-toggle-compose-cc" class="btn-outline" style="padding: 5px 12px; font-size: 13px;">Cc</button>
                </div>
                <input type="email" id="compose-email-cc" class="profile-form-input" placeholder="Cc (separate multiple with commas)" style="width: 100%; margin-bottom: 10px; display: none;">
                <input type="text" id="compose-email-subject" class="profile-form-input" placeholder="Subject" style="width: 100%; margin-bottom: 10px;">
                <textarea id="compose-email-body" class="profile-form-input" placeholder="Message" style="width: 100%; min-height: 160px; margin-bottom: 10px;"></textarea>
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                    <span style="font-weight: 600; color: var(--text-primary);">Attachments</span>
                    <button type="button" id="btn-add-compose-attachment" class="btn-outline" style="padding: 5px 12px; font-size: 13px;">+ Add</button>
                </div>
                <input type="file" id="compose-email-attachments-input" multiple style="display: none;">
                <div id="compose-email-attachments-list" style="display: flex; flex-wrap: wrap; gap: 10px;"></div>
                <div id="compose-email-progress" style="display: none; margin-top: 10px;">
                    <div style="height: 3px; background: var(--border-line); border-radius: 2px; overflow: hidden;">
                        <div id="compose-email-progress-bar" style="height: 100%; width: 0%; background: var(--primary-accent);"></div>
                    </div>
                </div>
            </div>
            <div id="btn-submit-compose-email" class="fab-button visible">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path></svg>
            </div>
        </div>

    </div>

    <!-- Main Chat Room -->
    <div class="chat-panel" id="chat-panel" style="display: none;">
        <div class="chat-header">
            <div id="btn-back" class="mobile-back-btn">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
            </div>
            <img src="https://ui-avatars.com/api/?name=Chat&background=FF5722&color=111" class="profile-pic" id="active-chat-img" alt="Chat">
            <div class="chat-header-info" id="chat-header-info-box" style="cursor: pointer;">
                <h3 id="active-chat-name">Select a chat</h3>
                <div id="active-chat-status" class="typing-indicator" style="display: none;">typing...</div>
            </div>
            <div class="chat-header-icons">
                <div class="icon-action" id="btn-chat-phone">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M20.01 15.38c-1.23 0-2.42-.2-3.53-.56-.35-.12-.74-.03-1.01.24l-1.57 1.97c-2.83-1.35-5.48-3.9-6.89-6.83l1.95-1.66c.27-.28.35-.67.24-1.02-.37-1.11-.56-2.3-.56-3.53 0-.54-.45-.99-.99-.99H4.19C3.65 3 3 3.24 3 3.99 3 13.28 10.73 21 20.03 21c.75 0 1-.65 1-1.19v-3.44c0-.54-.45-.99-.99-.99z"></path></svg>
                </div>
                <div class="icon-action" id="btn-chat-video">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"></path></svg>
                </div>
                <div class="chat-header-divider"></div>
                <div class="icon-action" id="btn-chat-search">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M15.9 14.3H15l-.3-.3c1-1.1 1.6-2.7 1.6-4.3 0-3.7-3-6.7-6.7-6.7S3 6 3 9.7s3 6.7 6.7 6.7c1.6 0 3.2-.6 4.3-1.6l.3.3v.8l5.1 5.1 1.5-1.5-5-5.2zm-6.2 0c-2.6 0-4.6-2.1-4.6-4.6s2.1-4.6 4.6-4.6 4.6 2.1 4.6 4.6-2 4.6-4.6 4.6z"></path></svg>
                </div>
                
                <div class="icon-action" id="btn-chat-menu">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 7a2 2 0 1 0-.001-4.001A2 2 0 0 0 12 7zm0 2a2 2 0 1 0-.001 3.999A2 2 0 0 0 12 9zm0 6a2 2 0 1 0-.001 3.999A2 2 0 0 0 12 15z"></path></svg>
                    <!-- Chat Dropdown -->
                    <div id="chat-dropdown" class="dropdown-menu">
                        <div class="dropdown-item" id="btn-contact-info">Contact info</div>
                        <div class="dropdown-item">Select messages</div>
                        <div class="dropdown-item">Close chat</div>
                        <div class="dropdown-item" id="btn-clear-chat">Clear chat</div>
                        <div class="dropdown-item">Delete chat</div>
                        <div class="dropdown-item" id="btn-block-user" style="color: #ef4444;">Block User</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="chat-messages" id="chat-messages">
        </div>

        <div id="blocked-banner" style="display: none; align-items: center; justify-content: center; background: var(--header-nav); padding: 15px; border-top: 1px solid var(--border-line); color: var(--text-muted); text-align: center;">
            <span>You have blocked this contact. <a href="#" id="btn-unblock-banner" style="color: var(--primary-accent); text-decoration: none; font-weight: 500;">Tap to unblock.</a></span>
        </div>

        <!-- Reply preview bar — mirrors the mobile client's composer reply preview -->
        <div id="reply-preview-bar" style="display: none; align-items: center; gap: 10px; padding: 8px 16px; background: var(--chat-background); border-top: 1px solid var(--border-line);">
            <div style="width: 3px; align-self: stretch; background: var(--primary-accent); border-radius: 2px;"></div>
            <div style="flex: 1; min-width: 0;">
                <div id="reply-preview-sender" style="font-size: 0.85rem; font-weight: 700; color: var(--primary-accent);"></div>
                <div id="reply-preview-text" style="font-size: 0.85rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"></div>
            </div>
            <div id="btn-close-reply-preview" style="cursor: pointer; padding: 4px; color: var(--text-muted);">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"></path></svg>
            </div>
        </div>

        <div class="chat-composer">
            <emoji-picker id="emoji-picker" class="dark" style="display: none; position: absolute; bottom: 70px; left: 16px; z-index: 100; background: var(--chat-background); border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid var(--border-line);"></emoji-picker>
            <div id="sticker-picker" style="display: none; position: absolute; bottom: 70px; left: 16px; width: 300px; max-height: 320px; overflow-y: auto; z-index: 100; background: var(--chat-background); border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid var(--border-line); padding: 10px; grid-template-columns: repeat(6, 1fr); gap: 4px;"></div>

            <!-- Attachment Menu -->
            <div id="attachment-menu" style="display: none; position: absolute; bottom: 70px; left: 55px; background: var(--chat-background); border-radius: 16px; padding: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); z-index: 3000; flex-direction: column; gap: 15px; border: 1px solid var(--border-line);">
                <div class="attachment-option" id="btn-attach-doc" style="display: flex; align-items: center; gap: 15px; cursor: pointer;">
                    <div style="width: 45px; height: 45px; border-radius: 50%; background: linear-gradient(135deg, #7c3aed, #4f46e5); display: flex; align-items: center; justify-content: center; color: white;">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"></path></svg>
                    </div>
                    <span style="font-size: 1rem; font-weight: 500;">Document</span>
                </div>
                <div class="attachment-option" id="btn-attach-media" style="display: flex; align-items: center; gap: 15px; cursor: pointer;">
                    <div style="width: 45px; height: 45px; border-radius: 50%; background: linear-gradient(135deg, #0ea5e9, #3b82f6); display: flex; align-items: center; justify-content: center; color: white;">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"></path></svg>
                    </div>
                    <span style="font-size: 1rem; font-weight: 500;">Photos & Videos</span>
                </div>
            </div>
            
            <input type="file" id="file-upload-media" style="display: none;" accept="image/*,video/*" multiple>
            <input type="file" id="file-upload-doc" style="display: none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt" multiple>
            
            <div class="composer-icons">
                <svg id="btn-smiley" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M9.153 11.603c.795 0 1.439-.879 1.439-1.962s-.644-1.962-1.439-1.962-1.439.879-1.439 1.962.644 1.962 1.439 1.962zm-3.204 1.362c-.026-.307-.131 5.218 6.063 5.551 6.066-.25 6.066-5.551 6.066-5.551-6.078 1.416-12.129 0-12.129 0zm11.363 1.108s-.669 1.959-5.051 1.959c-3.505 0-5.388-1.164-5.607-1.959 0 0 5.912 1.055 10.658 0zM11.804 1.011C5.609 1.011.978 6.033.978 12.228s4.826 10.761 11.021 10.761S23.02 18.423 23.02 12.228c.001-6.195-5.021-11.217-11.216-11.217zM12 21.354c-5.273 0-9.381-3.886-9.381-9.159s3.942-9.548 9.215-9.548 9.548 4.275 9.548 9.548c-.001 5.272-4.109 9.159-9.382 9.159zm3.108-9.751c.795 0 1.439-.879 1.439-1.962s-.644-1.962-1.439-1.962-1.439.879-1.439 1.962.644 1.962 1.439 1.962z"></path></svg>
                <svg id="btn-sticker" viewBox="0 0 24 24" width="22" height="22" fill="currentColor" title="Stickers"><path d="M19.78 4.22A2 2 0 0 0 18.36 3.64L5.64 3.64A2 2 0 0 0 3.64 5.64L3.64 18.36A2 2 0 0 0 5.64 20.36L14 20.36 20.36 14 20.36 5.64A2 2 0 0 0 19.78 4.22M13 18.5L13 15A2 2 0 0 1 15 13L18.5 13Z"></path></svg>
                <svg id="btn-paperclip" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M1.816 15.556v.002c0 1.502.584 2.912 1.646 3.972s2.472 1.647 3.974 1.647a5.58 5.58 0 0 0 3.972-1.645l9.547-9.548c.769-.768 1.147-1.767 1.058-2.817-.079-.968-.548-1.927-1.319-2.698-1.594-1.592-4.068-1.711-5.517-.262l-7.916 7.915c-.881.881-.792 2.25.214 3.261.959.958 2.423 1.053 3.263.215l5.511-5.512c.28-.28.267-.722.053-.936l-.244-.244c-.191-.191-.567-.349-.957.04l-5.506 5.506c-.18.18-.635.127-.976-.214-.098-.097-.576-.613-.213-.973l7.915-7.917c.818-.817 2.267-.699 3.23.262.5.501.802 1.1.849 1.685.051.573-.156 1.111-.589 1.543l-9.547 9.549a3.97 3.97 0 0 1-2.829 1.171 3.975 3.975 0 0 1-2.83-1.173 3.973 3.973 0 0 1-1.172-2.828c0-1.071.415-2.076 1.172-2.83l7.209-7.211c.157-.157.264-.579.028-.814L11.5 4.36a.572.572 0 0 0-.834.018l-7.205 7.207a5.577 5.577 0 0 0-1.645 3.971z"></path></svg>
                <svg id="btn-chat-payment" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" title="Send payment">
                    <path d="M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1zm1 3v8h14V8H5zm7-2a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm-1.08 8.8v-.9c-1.22-.15-2.14-.93-2.24-2.1h1.54c.08.62.58 1 1.32 1 .77 0 1.22-.31 1.22-.8 0-.46-.34-.69-1.3-.92-1.4-.33-2.54-.75-2.54-2.18 0-1.1.84-1.88 2.12-2.04V7.6h1.16v.91c1.16.18 1.9.9 2 2.01h-1.51c-.09-.54-.49-.86-1.15-.86-.67 0-1.08.3-1.08.75 0 .44.39.64 1.38.88 1.47.35 2.48.86 2.48 2.23 0 1.12-.87 1.92-2.24 2.08v.94h-1.16z"></path>
                </svg>
            </div>
            <div class="composer-input-wrapper">
                <input type="text" id="message-input" class="composer-input" placeholder="Type a message">
            </div>
            <div class="composer-icons" id="btn-mic" style="cursor:pointer; position: relative;">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M11.999 14.942c2.001 0 3.531-1.53 3.531-3.531V4.35c0-2.001-1.53-3.531-3.531-3.531S8.469 2.35 8.469 4.35v7.061c0 2.001 1.53 3.531 3.53 3.531zm6.238-3.53c0 3.531-2.942 6.002-6.237 6.002s-6.237-2.471-6.237-6.002H3.761c0 4.001 3.178 7.297 7.061 7.885v3.884h2.354v-3.884c3.884-.588 7.061-3.884 7.061-7.885h-2.001z"></path></svg>
            </div>
            <div class="composer-icons" id="btn-send" style="cursor:pointer; display:none;">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M1.101 21.757 23.8 12.028 1.101 2.3l.011 7.912 13.623 1.816-13.623 1.817-.011 7.912z"></path></svg>
            </div>
        </div>
        
        <!-- Selection Mode Action Bar -->
        <div id="selection-action-bar" style="display: none; align-items: center; justify-content: space-between; padding: 15px 25px; background: var(--header-nav); border-top: 1px solid var(--border-line); height: 70px;">
            <div style="font-weight: 500; font-size: 1.1rem;"><span id="selection-count">0</span> selected</div>
            <div style="display: flex; gap: 15px;">
                <button id="btn-cancel-selection" class="btn-outline" style="padding: 8px 16px;">Cancel</button>
                <button id="btn-delete-selection" class="btn-primary" style="padding: 8px 16px; background: #ef4444; border-color: #ef4444;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" style="vertical-align: middle; margin-right: 5px;"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"></path></svg>
                    Delete
                </button>
            </div>
        </div>
        
        <!-- Attachment Preview Panel Overlay -->
        <div id="attachment-preview-panel" style="display: none; position: absolute; inset: 0; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(24px); z-index: 2000; flex-direction: column;">
            
            <!-- Floating Header -->
            <div style="height: 70px; display: flex; align-items: center; padding: 0 20px; position: absolute; top: 0; left: 0; right: 0; z-index: 10;">
                <div id="btn-close-preview" style="cursor: pointer; padding: 10px; border-radius: 50%; background: rgba(255,255,255,0.1); color: white; display: flex; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.2)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"></path></svg>
                </div>
            </div>
            
            <!-- Main Stage -->
            <div style="flex: 1; display: flex; align-items: center; justify-content: center; padding: 60px 20px 120px 20px; overflow: hidden; position: relative;">
                <div id="preview-stage" style="max-width: 90%; max-height: 90%; display: flex; align-items: center; justify-content: center; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.5);">
                    <!-- Large preview goes here -->
                </div>
            </div>
            
            <!-- Floating Dock Bottom Controls -->
            <div style="position: absolute; bottom: 30px; left: 0; right: 0; display: flex; justify-content: center; align-items: center; gap: 20px; padding: 0 20px; z-index: 10; pointer-events: none;">
                
                <div id="btn-add-more-attachments" style="pointer-events: auto; width: 48px; height: 48px; border-radius: 50%; background: rgba(255,255,255,0.1); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; cursor: pointer; color: white; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.2)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"></path></svg>
                </div>
                
                <div style="pointer-events: auto; display: flex; align-items: center; gap: 10px; overflow-x: auto; background: rgba(0,0,0,0.4); backdrop-filter: blur(20px); padding: 8px 12px; border-radius: 30px; border: 1px solid rgba(255,255,255,0.1); max-width: 60vw;" id="preview-thumbnail-list">
                    <!-- Thumbnails go here -->
                </div>
                
                <div id="btn-send-attachments" style="pointer-events: auto; width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, #10b981, #059669); display: flex; align-items: center; justify-content: center; cursor: pointer; color: white; box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4); transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor" style="transform: translateX(2px);"><path d="M1.101 21.757 23.8 12.028 1.101 2.3l.011 7.912 13.623 1.816-13.623 1.817-.011 7.912z"></path></svg>
                </div>
                
            </div>
        </div>
    </div>
    
    <!-- Empty State / Dashboard -->
    <div id="empty-state" class="empty-state">
        <div class="empty-state-hero">
            <div class="empty-state-icon">
                <svg viewBox="0 0 24 24" width="40" height="40" fill="white"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path></svg>
            </div>
            <h2>Welcome Back</h2>
            <p>Select a recent conversation below or start a new one to begin.</p>
        </div>
        
        <div id="dashboard-chat-grid" class="dashboard-grid">
            <!-- Dashboard chat cards will be populated here by JS -->
        </div>
        
        <div class="encrypted-badge">
            <svg viewBox="0 0 10 12" width="10" height="12" fill="currentColor"><path d="M5.008 1.6A2.68 2.68 0 0 0 2.34 4.267v1.867H2a.936.936 0 0 0-.933.933v3.733A.936.936 0 0 0 2 11.733h6.016a.936.936 0 0 0 .934-.933V7.067A.936.936 0 0 0 8.016 6.133h-.34V4.267A2.68 2.68 0 0 0 5.008 1.6zM3.54 6.133V4.267a1.467 1.467 0 0 1 2.934 0v1.866H3.54z"></path></svg>
            End-to-end encrypted
        </div>
    </div>

    <!-- Right Sidebar (Contact Info / Search) -->
    <div class="right-sidebar" id="right-sidebar">
        <div class="right-sidebar-header">
            <div id="btn-close-right-sidebar" style="cursor: pointer;">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M12 20.664L3.336 12 12 3.336l1.414 1.414L6.164 11h14.5v2H6.164l7.25 7.25z"></path></svg>
            </div>
            <span id="right-sidebar-title">Contact info</span>
        </div>
        
        <!-- Contact Info Pane -->
        <div class="right-sidebar-content" id="pane-contact-info">
            <img src="" id="contact-info-img" class="contact-info-img" alt="Contact">
            <h2 id="contact-info-name" class="contact-info-name">Name</h2>
            <div id="contact-info-phone" class="contact-info-phone">+1 555-5555</div>
            
            <div class="contact-info-card">
                <div class="contact-info-card-label">About</div>
                <div id="contact-info-about" class="contact-info-card-value">Available</div>
            </div>

            <div class="contact-info-card" id="contact-save-card" style="margin-top: 15px;">
                <div class="contact-info-card-label">Contact Name</div>
                <div style="display: flex; gap: 8px; margin-top: 8px;">
                    <input type="text" id="contact-save-name" placeholder="Enter custom name" class="form-control" style="background: var(--input-fields); color: var(--text-primary); border: 1px solid var(--border-line); border-radius: var(--radius-sm); padding: 8px 12px; flex: 1;">
                    <button id="btn-save-contact" class="btn btn-primary" style="padding: 8px 16px;">Save</button>
                </div>
            </div>
            
            <div id="ci-block-btn-container" style="background: var(--cards); padding: 15px; border-radius: 12px; text-align: left; cursor: pointer; color: #ef4444; font-weight: 500; display: flex; align-items: center; gap: 10px; border: 1px solid var(--border-line); margin-top: 15px;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zM4 12c0-4.42 3.58-8 8-8 1.85 0 3.55.63 4.9 1.69L5.69 16.9C4.63 15.55 4 13.85 4 12zm8 8c-1.85 0-3.55-.63-4.9-1.69L18.31 7.1C19.37 8.45 20 10.15 20 12c0 4.42-3.58 8-8 8z"></path></svg>
                <span id="ci-block-text">Block User</span>
            </div>
        </div>

        <!-- Group Info Pane -->
        <div class="right-sidebar-content" id="pane-group-info" style="display: none;">
            <div class="group-photo-upload-wrapper">
                <img src="https://ui-avatars.com/api/?name=Group" id="group-info-img" class="contact-info-img" alt="Group Image">
                <div class="group-photo-overlay" id="btn-group-photo-upload" title="Change Group Icon">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M21.2 5.5h-3.9l-1.3-1.4c-.4-.5-1-.8-1.7-.8h-4.6c-.6 0-1.2.3-1.6.8L6.8 5.5H2.8C1.3 5.5 0 6.8 0 8.3v10.5C0 20.2 1.3 21.5 2.8 21.5h18.4c1.5 0 2.8-1.3 2.8-2.8V8.3c0-1.5-1.2-2.8-2.8-2.8zm-9.2 12.3c-3 0-5.5-2.5-5.5-5.5s2.5-5.5 5.5-5.5 5.5 2.5 5.5 5.5-2.5 5.5-5.5 5.5zm0-9c-1.9 0-3.5 1.6-3.5 3.5s1.6 3.5 3.5 3.5 3.5-1.6 3.5-3.5-1.6-3.5-3.5-3.5z"></path></svg>
                </div>
                <input type="file" id="group-photo-input" accept="image/*" style="display:none;">
            </div>
            
            <h2 id="group-info-name" class="contact-info-name">Group Name</h2>
            <div id="group-info-meta" class="contact-info-phone">Group • 1 participant</div>
            
            <div class="contact-info-card" style="margin-top: 20px;">
                <div class="contact-info-card-label" style="display: flex; justify-content: space-between;">
                    <span>Group Members</span>
                    <span id="group-member-count">1</span>
                </div>
                <div id="btn-add-participant" style="display: none;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"></path></svg>
                    <span>Add Participant</span>
                </div>
                <div id="group-members-list" class="group-members-list">
                    <!-- JS populated members -->
                </div>
            </div>
            
            <div class="contact-info-card" id="group-leave-card" style="margin-top: 20px;">
                <div class="contact-info-card-value text-danger" id="btn-leave-group" style="cursor: pointer; display: flex; align-items: center; justify-content: center;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" style="margin-right:15px;"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"></path></svg>
                    <span>Leave Group</span>
                </div>
            </div>
        </div>
        
        <!-- In-Chat Search Pane -->
        <div class="right-sidebar-content" id="pane-chat-search" style="display: none; padding: 0;">
            <div style="padding: 15px; width: 100%; border-bottom: 1px solid var(--border-line);">
                <div class="search-input-wrapper">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="var(--text-muted)" style="margin-right: 8px; flex-shrink: 0;"><path d="M15.009 13.805h-.636l-.22-.219a5.184 5.184 0 0 0 1.256-3.386 5.207 5.207 0 1 0-5.207 5.208 5.183 5.183 0 0 0 3.385-1.255l.221.22v.635l4.004 3.999 1.194-1.195-3.997-4.007zm-4.808 0a3.605 3.605 0 1 1 0-7.21 3.605 3.605 0 0 1 0 7.21z"></path></svg>
                    <input type="text" id="in-chat-search-input" class="search-input" placeholder="Search messages">
                </div>
            </div>
            <div id="in-chat-search-results" class="loading-text">
                Search for messages in this chat.
            </div>
        </div>
    </div>

</div>
<!-- Profile Modal -->
<div id="profile-modal" class="profile-modal-backdrop">
    <div class="profile-modal-card">
        <h2 class="profile-modal-title">Edit Profile</h2>

        <div class="profile-avatar-wrapper" style="text-align: center; margin-bottom: 24px; position: relative; width: 120px; height: 120px; margin-left: auto; margin-right: auto;">
            <img id="profile-avatar-preview" src="" alt="Profile Picture" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary-accent);">
            <div class="profile-avatar-overlay" id="btn-change-avatar" style="position: absolute; bottom: 0; right: 0; background: var(--primary-accent); color: white; border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; cursor: pointer; border: 2px solid var(--panel-sidebar); transition: transform 0.2s;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M4 4h3l2-2h6l2 2h3c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zM12 18c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm0-8c1.65 0 3 1.35 3 3s-1.35 3-3 3-3-1.35-3-3 1.35-3 3-3z"></path></svg>
            </div>
            <input type="file" id="profile-photo-input" accept="image/*" style="display: none;">
        </div>

        <div style="display: flex; gap: 16px;">
            <div class="profile-form-group" style="flex: 1;">
                <label class="profile-form-label">First Name</label>
                <input type="text" id="profile-first-name" class="profile-form-input">
            </div>
            <div class="profile-form-group" style="flex: 1;">
                <label class="profile-form-label">Last Name</label>
                <input type="text" id="profile-last-name" class="profile-form-input">
            </div>
        </div>

        <div class="profile-form-group">
            <label class="profile-form-label">Username</label>
            <input type="text" id="profile-name" class="profile-form-input" oninput="this.value = this.value.replace(/\s/g, '')">
        </div>

        <div class="profile-form-group">
            <label class="profile-form-label">Email</label>
            <input type="email" id="profile-email" class="profile-form-input">
        </div>

        <div class="profile-form-group">
            <label class="profile-form-label">About</label>
            <input type="text" id="profile-about" class="profile-form-input">
        </div>

        <div class="profile-modal-actions">
            <form action="/logout" method="POST" style="margin: 0;">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <button type="submit" class="btn-outline danger">Log Out</button>
            </form>
            <div class="btn-group">
                <button id="btn-close-profile" class="btn-outline">Cancel</button>
                <button id="btn-save-profile" class="btn-solid">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Status Hub Overlay -->
<div id="status-overlay" class="status-overlay">
    <div class="status-overlay-header">
        <div class="status-overlay-header-left">
            <svg id="btn-close-status" viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"></path></svg>
            <h2>Updates</h2>
        </div>
    </div>
    
    <div class="status-hub-content">
        <div class="status-horizontal-scroll">
            <!-- My Status Add Button -->
            <div class="status-thumbnail my-status-btn" id="btn-my-status">
                <div class="status-thumbnail-ring add-ring">
                    <img src="{{ auth()->user()->photo_url ?? 'https://ui-avatars.com/api/?name='.urlencode(auth()->user()->username).'&background=random' }}" class="status-thumbnail-pic">
                    <div class="status-add-icon">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"></path></svg>
                    </div>
                </div>
                <div class="status-thumbnail-name">My Status</div>
            </div>

            <!-- Dynamic thumbnails will be appended here -->
            <div id="status-list-container" class="status-list-container"></div>
        </div>

        <div id="status-empty-state" class="status-empty">
            No recent updates from friends.
        </div>
    </div>

    <!-- Active Status Viewer -->
    <div id="active-status-viewer" class="status-viewer">
        <div class="status-viewer-backdrop" id="status-viewer-backdrop"></div>
        
        <div class="status-viewer-header">
            <div class="status-progress-bars" id="status-progress-bars"></div>
            <div class="status-viewer-top-bar">
                <div class="status-viewer-user-info" id="status-viewer-user-info">
                    <!-- User info populated by JS -->
                </div>
                <div class="status-viewer-controls">
                    <svg id="btn-status-play-pause" viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                        <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"></path>
                    </svg>
                    <svg id="btn-close-viewer" viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"></path></svg>
                </div>
            </div>
        </div>

        <div class="status-nav left" id="status-nav-left">
            <svg viewBox="0 0 24 24" width="36" height="36" fill="currentColor"><path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"></path></svg>
        </div>
        <div id="active-status-content" class="status-content"></div>
        <div class="status-nav right" id="status-nav-right">
            <svg viewBox="0 0 24 24" width="36" height="36" fill="currentColor"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"></path></svg>
        </div>
    </div>

    <!-- Status Creation Modal -->
    <div id="status-create-modal" class="status-create-modal">
        <div class="status-create-header">
            <svg id="btn-close-create-status" viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"></path></svg>
            <h2>Create Status</h2>
        </div>
        
        <div class="status-create-body" id="status-create-preview" style="background: #6366f1;">
            <textarea id="status-create-text" placeholder="Type a status..." maxlength="250"></textarea>
            <img id="status-media-img-preview" style="display:none;">
            <video id="status-media-vid-preview" autoplay loop muted playsinline style="display:none;"></video>
        </div>
        
        <div class="status-create-footer">
            <div class="status-create-tools">
                <svg id="btn-status-attach" viewBox="0 0 24 24" width="28" height="28" fill="currentColor" style="cursor: pointer; opacity: 0.8; margin-right: 15px; transition: opacity 0.2s;"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"></path></svg>
                <input type="file" id="status-media-input" accept="image/*,video/*" style="display:none;">
                <div class="status-color-picker" id="status-color-picker">
                    <!-- Color options populated by JS -->
                </div>
            </div>
            <button id="btn-send-status" class="btn-send-status">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path></svg>
            </button>
        </div>
    </div>
</div>

<!-- Call Overlay -->
<div id="call-overlay" class="call-overlay">
    <video id="remote-video" class="remote-video" autoplay playsinline></video>
    <video id="local-video" class="local-video" autoplay playsinline muted></video>
    
    <div id="call-info-container" class="call-info-container">
        <div id="call-status-text" class="call-status-text">Ringing...</div>
        <img id="call-avatar" src="" class="call-avatar" alt="Caller">
        <div id="call-name" class="call-name">User Name</div>
        <div id="call-timer" class="call-timer">00:00</div>
    </div>
    
    <div id="call-incoming-actions" class="call-actions">
        <button id="btn-call-decline" class="call-btn decline">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85-.18.18-.43.28-.7.28-.28 0-.53-.11-.71-.29L.29 13.08c-.18-.17-.29-.42-.29-.7 0-.28.11-.53.29-.71C3.34 8.78 7.46 7 12 7s8.66 1.78 11.71 4.67c.18.18.29.43.29.71 0 .28-.11.53-.29.71l-2.48 2.48c-.18.18-.43.29-.71.29-.27 0-.52-.11-.7-.28-.79-.74-1.69-1.36-2.67-1.85-.33-.16-.56-.5-.56-.9v-3.1C15.15 9.25 13.6 9 12 9z"></path></svg>
        </button>
        <button id="btn-call-accept" class="call-btn accept">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M20.01 15.38c-1.23 0-2.42-.2-3.53-.56-.35-.12-.74-.03-1.01.24l-1.57 1.97c-2.83-1.35-5.48-3.9-6.89-6.83l1.95-1.66c.27-.28.35-.67.24-1.02-.37-1.11-.56-2.3-.56-3.53 0-.54-.45-.99-.99-.99H4.19C3.65 3 3 3.24 3 3.99 3 13.28 10.73 21 20.03 21c.75 0 1-.65 1-1.19v-3.44c0-.54-.45-.99-.99-.99z"></path></svg>
        </button>
    </div>
    
    <div id="call-active-actions" class="call-actions">
        <button id="btn-call-mute" class="call-btn" style="background-color: rgba(255, 255, 255, 0.2); color: white; backdrop-filter: blur(10px); margin-right: 15px;">
            <svg id="icon-mic-on" viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M12 14c1.66 0 2.99-1.34 2.99-3L15 5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z"></path></svg>
            <svg id="icon-mic-off" viewBox="0 0 24 24" width="28" height="28" fill="currentColor" style="display: none;"><path d="M19 11h-1.7c0 .74-.16 1.43-.43 2.05l1.23 1.23c.56-.98.9-2.09.9-3.28zm-4.02 3.28l1.27 1.27C15.37 16.32 13.84 17 12 17c-2.76 0-5.3-2.1-5.3-5.1H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c1.91-.28 3.59-1.31 4.74-2.72.25.32.48.67.74.98zm-9.5-8.86L4.21 4.21l15.58 15.58 1.27-1.27-5.58-5.58C15.82 11.53 16 11.27 16 11V5c0-1.66-1.34-3-3-3S10 3.34 10 5v1.76l-4.52-4.52zM12 14c-.66 0-1.22-.26-1.66-.72L14.72 9c.16.29.28.61.28.96v1.04C15 12.66 13.66 14 12 14z"></path></svg>
        </button>
        <button id="btn-call-end" class="call-btn end">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M12 9c-1.6 0-3.15.25-4.6.72v3.1c0 .39-.23.74-.56.9-.98.49-1.87 1.12-2.66 1.85-.18.18-.43.28-.7.28-.28 0-.53-.11-.71-.29L.29 13.08c-.18-.17-.29-.42-.29-.7 0-.28.11-.53.29-.71C3.34 8.78 7.46 7 12 7s8.66 1.78 11.71 4.67c.18.18.29.43.29.71 0 .28-.11.53-.29.71l-2.48 2.48c-.18.18-.43.29-.71.29-.27 0-.52-.11-.7-.28-.79-.74-1.69-1.36-2.67-1.85-.33-.16-.56-.5-.56-.9v-3.1C15.15 9.25 13.6 9 12 9z"></path></svg>
        </button>
    </div>
</div>

    <!-- Message Context Menu -->
    <div id="message-context-menu" style="display: none; position: absolute; background: var(--bg-dropdown); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); border: 1px solid var(--border-line); z-index: 3500; min-width: 180px; overflow: hidden; padding: 5px 0;">
        <div style="display: flex; justify-content: space-around; padding: 6px 8px; border-bottom: 1px solid var(--border-line);">
            <span class="quick-react-emoji" data-emoji="👍" style="cursor: pointer; font-size: 1.2rem;">👍</span>
            <span class="quick-react-emoji" data-emoji="❤️" style="cursor: pointer; font-size: 1.2rem;">❤️</span>
            <span class="quick-react-emoji" data-emoji="😂" style="cursor: pointer; font-size: 1.2rem;">😂</span>
            <span class="quick-react-emoji" data-emoji="😮" style="cursor: pointer; font-size: 1.2rem;">😮</span>
            <span class="quick-react-emoji" data-emoji="😢" style="cursor: pointer; font-size: 1.2rem;">😢</span>
            <span class="quick-react-emoji" data-emoji="🙏" style="cursor: pointer; font-size: 1.2rem;">🙏</span>
            <span id="btn-react-more" style="cursor: pointer; font-size: 1.2rem;">➕</span>
        </div>
        <div class="dropdown-item" id="btn-reply-msg" style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; cursor: pointer;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"></path></svg>
            Reply
        </div>
        <div class="dropdown-item" id="btn-share-msg-whatsapp" style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; cursor: pointer;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2z"></path></svg>
            Share via WhatsApp
        </div>
        <div class="dropdown-item" id="btn-share-msg-email" style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; cursor: pointer;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"></path></svg>
            Share via Email
        </div>
        <div class="dropdown-item" id="btn-share-msg-more" style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; cursor: pointer;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L7.04 9.81C6.5 9.31 5.79 9 5 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"></path></svg>
            Share via…
        </div>
        <div class="dropdown-item" id="btn-delete-msg-me" style="display: flex; align-items: center; gap: 8px; padding: 10px 15px; cursor: pointer;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"></path></svg>
            Delete for me
        </div>
        <div class="dropdown-item" id="btn-delete-msg-everyone" style="color: #ef4444; display: flex; align-items: center; gap: 8px; padding: 10px 15px; cursor: pointer;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M15 4V3H9v1H4v2h1v13c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V6h1V4h-5zm2 15H7V6h10v13z"></path><path d="M9 8h2v9H9zm4 0h2v9h-2z"></path></svg>
            Delete for everyone
        </div>
        <div class="dropdown-item" id="btn-report-msg" style="color: #ef4444; display: flex; align-items: center; gap: 8px; padding: 10px 15px; cursor: pointer;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M14.4 6L14 4H5v17h2v-7h5.6l.4 2h7V6z"></path></svg>
            Report
        </div>
    </div>

    <!-- Report message: reason picker -->
    <div id="report-message-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 3600; align-items: center; justify-content: center;">
        <div style="background: var(--panel-sidebar); width: 380px; max-width: 90%; border-radius: var(--radius-lg); padding: 20px;">
            <h3 style="margin: 0 0 12px 0;">Report this message</h3>
            <div id="report-reason-list">
                <div class="dropdown-item report-reason-option" data-reason="Spam" style="padding: 10px 4px; cursor: pointer; border-bottom: 1px solid var(--border-line);">Spam</div>
                <div class="dropdown-item report-reason-option" data-reason="Inappropriate content" style="padding: 10px 4px; cursor: pointer; border-bottom: 1px solid var(--border-line);">Inappropriate content</div>
                <div class="dropdown-item report-reason-option" data-reason="Harassment or bullying" style="padding: 10px 4px; cursor: pointer; border-bottom: 1px solid var(--border-line);">Harassment or bullying</div>
                <div class="dropdown-item report-reason-option" data-reason="Other" style="padding: 10px 4px; cursor: pointer;">Other</div>
            </div>
            <textarea id="report-details-input" placeholder="Add details (optional)" style="display: none; width: 100%; margin-top: 10px; min-height: 70px; box-sizing: border-box; background: var(--chat-background); color: var(--text-primary); border: 1px solid var(--border-line); border-radius: var(--radius-md); padding: 8px;"></textarea>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px;">
                <button type="button" id="btn-report-cancel" class="profile-btn-cancel">Cancel</button>
                <button type="button" id="btn-report-submit" class="btn-primary" style="display: none; padding: 8px 16px; border-radius: 8px;">Submit</button>
            </div>
        </div>
    </div>

    <!-- Dedicated to message reactions — kept separate from #emoji-picker so
         opening it never inserts into the message composer's text input. -->
    <emoji-picker id="reaction-emoji-picker" class="dark" style="display: none; position: absolute; z-index: 3600; background: var(--chat-background); border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); border: 1px solid var(--border-line);"></emoji-picker>

    <!-- Image Gallery Slider -->
    <div id="image-gallery-slider" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.95); z-index: 5000; flex-direction: column;">
        <div style="height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; border-bottom: 1px solid rgba(255,255,255,0.1);">
            <div id="gallery-counter" style="color: white; font-weight: 500; font-size: 1.1rem;">1 of 1</div>
            <div id="btn-close-gallery" style="cursor: pointer; color: white; padding: 10px;">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"></path></svg>
            </div>
        </div>
        
        <div style="flex: 1; display: flex; align-items: center; justify-content: center; position: relative;">
            <div id="btn-gallery-prev" style="position: absolute; left: 20px; width: 50px; height: 50px; border-radius: 50%; background: rgba(255,255,255,0.1); color: white; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; z-index: 10;">
                <svg viewBox="0 0 24 24" width="32" height="32" fill="currentColor"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"></path></svg>
            </div>
            
            <div id="gallery-stage" style="width: 80%; height: 90%; display: flex; align-items: center; justify-content: center; position: relative;">
                <img src="" id="gallery-main-img" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 8px; transition: opacity 0.2s;">
                <video src="" id="gallery-main-video" style="display: none; max-width: 100%; max-height: 100%; border-radius: 8px; outline: none;" controls></video>
            </div>
            
            <div id="btn-gallery-next" style="position: absolute; right: 20px; width: 50px; height: 50px; border-radius: 50%; background: rgba(255,255,255,0.1); color: white; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; z-index: 10;">
                <svg viewBox="0 0 24 24" width="32" height="32" fill="currentColor"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"></path></svg>
            </div>
        </div>
    </div>

<!-- Logout Form -->
<form id="logout-form" action="/logout" method="POST" style="display: none;">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
</form>

<!-- Data passed to JS -->
<script>
    window.APP_USER = @json(auth()->user());
    window.API_TOKEN = '{{ session("api_token") }}';
</script>

<!-- Settings Modal -->
<div id="settings-modal" class="profile-modal-backdrop">
    <div class="profile-modal-card">
        <h2 class="profile-modal-title">Settings</h2>
        
        <div class="profile-form-group" style="display: flex; justify-content: space-between; align-items: center;">
            <label class="profile-form-label" style="margin-bottom: 0;">Mute Notification Sounds</label>
            <input type="checkbox" id="setting-mute-sounds" style="width: 20px; height: 20px;">
        </div>

        <div class="profile-form-group" style="display: flex; justify-content: space-between; align-items: center;">
            <label class="profile-form-label" style="margin-bottom: 0;">Press Enter to Send</label>
            <input type="checkbox" id="setting-enter-send" style="width: 20px; height: 20px;">
        </div>

        <hr style="border: 0; border-top: 1px solid var(--border-line); margin: 20px 0;">
        <h3 style="font-size: 1.1rem; color: var(--text-primary); margin-bottom: 15px;">Status Privacy</h3>

        <div class="profile-form-group">
            <label class="profile-form-label">Who can see my updates</label>
            <select id="setting-status-privacy" class="profile-form-input" style="background: var(--input-fields); color: var(--text-primary); border: 1px solid var(--border-line); padding: 10px; border-radius: var(--radius-sm); width: 100%; outline: none;" onchange="togglePrivacyListInput()">
                <option value="contacts">My Contacts</option>
                <option value="everyone">Everyone</option>
                <option value="selected">Only Share With...</option>
                <option value="exclude">My Contacts Except...</option>
            </select>
        </div>

        <div class="profile-form-group" id="setting-status-privacy-list-wrapper" style="display: none;">
            <label class="profile-form-label">Usernames (comma-separated)</label>
            <input type="text" id="setting-status-privacy-list" class="profile-form-input" placeholder="e.g. johndoe, janed">
            <small style="color: var(--text-muted); font-size: 0.8rem; margin-top: 5px; display: block;">Enter exact usernames to include or exclude.</small>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px;">
            <button class="profile-btn-cancel" onclick="closeSettingsModal()">Close</button>
            <button class="profile-btn-save" id="btn-save-settings" onclick="saveSettings()">Save</button>
        </div>
    </div>
</div>

<!-- Payments Modal -->
<div id="payments-modal" class="profile-modal-backdrop payments-modal-backdrop">
    <div class="profile-modal-card payments-modal-card">
        <h2 class="profile-modal-title">Sampay Wallet</h2>
        
        <div id="payments-loading" class="payments-loading">
            <p style="color: var(--text-muted);">Checking linked account...</p>
        </div>

        <div id="payments-linked-view" class="payments-view-block" style="display: none;">
            <div class="payments-status-card">
                <h3 style="margin-top: 0; color: var(--text-primary);">Linked Account</h3>
                <p style="margin: 5px 0; color: var(--text-muted);"><strong>Username:</strong> <span id="sampay-username-text"></span></p>
                <p style="margin: 5px 0; color: var(--text-muted);"><strong>Mobile:</strong> <span id="sampay-mobile-text"></span></p>
            </div>
            <button class="profile-btn-cancel profile-btn-danger full-width mt-15" id="btn-unlink-sampay">Unlink Account</button>
        </div>

        <div id="payments-unlinked-view" class="payments-view-block text-center" style="display: none;">
            <p class="payments-helper-text">Link your Sampay Wallet to request and receive payments in chat.</p>
            <button class="profile-btn-save full-width" id="btn-link-sampay">Link Sampay Account</button>
        </div>

        <div class="payments-footer-actions">
            <button class="profile-btn-cancel" id="btn-close-payments-modal">Close</button>
        </div>
    </div>
</div>

<!-- In-chat Send Payment Modal -->
<div id="chat-payment-modal" class="profile-modal-backdrop">
    <div class="profile-modal-card chat-payment-card">
        <h2 class="profile-modal-title">Send In-Chat Payment</h2>

        <div class="chat-payment-target" id="chat-payment-target-text">Target user: -</div>

        <div class="profile-form-group">
            <label class="profile-form-label">Amount (ZMW)</label>
            <input type="number" id="chat-payment-amount" class="profile-form-input" min="1" step="0.01" placeholder="e.g. 150.00">
        </div>

        <div class="profile-form-group">
            <label class="profile-form-label">Recipient Account Type</label>
            <select id="chat-payment-recipient-type" class="profile-form-input" style="background: var(--input-fields); color: var(--text-primary); border: 1px solid var(--border-line); padding: 10px; border-radius: var(--radius-sm); width: 100%; outline: none;">
                <option value="personal">Personal</option>
                <option value="business">Business</option>
            </select>
        </div>

        <div class="profile-form-group">
            <label class="profile-form-label">Recipient Account</label>
            <input type="text" id="chat-payment-recipient-account" class="profile-form-input" maxlength="255" placeholder="Account #">
        </div>

        <div class="profile-form-group">
            <label class="profile-form-label">Purpose</label>
            <select id="chat-payment-purpose" class="profile-form-input" style="background: var(--input-fields); color: var(--text-primary); border: 1px solid var(--border-line); padding: 10px; border-radius: var(--radius-sm); width: 100%; outline: none;">
                <option value="">Select a purpose</option>
                <option value="Bill split">Bill split</option>
                <option value="Rent">Rent</option>
                <option value="Groceries">Groceries</option>
                <option value="Loan repayment">Loan repayment</option>
                <option value="Gift">Gift</option>
                <option value="Goods payment">Goods payment</option>
                <option value="Service payment">Service payment</option>
                <option value="other">Other (specify)</option>
            </select>
        </div>

        <div class="profile-form-group" id="chat-payment-purpose-other-group" style="display: none;">
            <label class="profile-form-label">Specify Purpose</label>
            <input type="text" id="chat-payment-purpose-other" class="profile-form-input" maxlength="120" placeholder="Enter payment purpose">
        </div>

        <div class="profile-form-group">
            <label class="profile-form-label">Remarks (Optional)</label>
            <input type="text" id="chat-payment-remarks" class="profile-form-input" maxlength="255" placeholder="Optional note">
        </div>

        <div class="payments-footer-actions">
            <button class="profile-btn-cancel" id="btn-close-chat-payment-modal">Cancel</button>
            <button class="profile-btn-save" id="btn-submit-chat-payment">Send Payment</button>
        </div>
    </div>
</div>

<!-- Group Payment Recipient Picker Modal -->
<div id="chat-payment-recipient-modal" class="profile-modal-backdrop">
    <div class="profile-modal-card">
        <h2 class="profile-modal-title">Choose recipient</h2>
        <div id="chat-payment-recipient-list" style="max-height: 320px; overflow-y: auto;"></div>
        <div class="payments-footer-actions">
            <button class="profile-btn-cancel" id="btn-close-chat-payment-recipient-modal">Cancel</button>
        </div>
    </div>
</div>

    <!-- Add Participant Modal -->
    <div id="add-participant-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: var(--panel-sidebar); width: 400px; max-width: 90%; border-radius: var(--radius-lg); overflow: hidden; display: flex; flex-direction: column;">
            <div style="padding: 20px; border-bottom: 1px solid var(--border-line); display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;">Add Participants</h3>
                <svg id="btn-close-add-participant" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="cursor: pointer;"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"></path></svg>
            </div>
            <div style="padding: 15px;">
                <input type="text" id="add-participant-search" placeholder="Search users..." style="width: 100%; padding: 10px; border-radius: var(--radius-md); border: 1px solid var(--border-line); background: var(--chat-background); color: var(--text-primary); outline: none;">
            </div>
            <div id="add-participant-list" style="flex: 1; max-height: 300px; overflow-y: auto; padding: 0 15px;">
                <!-- JS populated -->
            </div>
            <div style="padding: 20px; border-top: 1px solid var(--border-line);">
                <button id="btn-submit-add-participants" class="btn-primary" style="width: 100%; padding: 12px; border-radius: 8px; font-weight: 600;">Add Selected</button>
            </div>
        </div>
    </div>

</body>
</html>


