import re

def process_file(filepath, replacements):
    with open(filepath, 'r') as f:
        content = f.read()
    
    # First, let's remove the old :root and body.dark-theme blocks entirely
    # and we will prepend the new blocks.
    
    # Remove :root block
    # This regex handles nested brackets (assuming no nested brackets in :root or at most 1 level if there's syntax errors, but standard CSS :root doesn't have nested brackets)
    content = re.sub(r':root\s*\{[^}]*\}', '/* :root replacement marker */', content, count=1)
    
    # Remove body.dark-theme block
    content = re.sub(r'body\.dark-theme\s*\{[^}]*\}', '/* dark-theme replacement marker */', content, count=1)
    
    # Replace variable usages
    for old_var, new_var in replacements.items():
        content = re.sub(r'var\(' + re.escape(old_var) + r'\)', f'var({new_var})', content)
        # also handle cases with fallbacks, e.g. var(--old, default) - wait, this might be too complex and not needed, let's stick to simple var(--old)

    # Note: --bubble-sent was previously a linear gradient, but now the user provided 
    # --sent-bubble-gradient-start and --sent-bubble-gradient-end
    # The old usage was: background: var(--bubble-sent);
    # I should replace var(--bubble-sent) with linear-gradient(135deg, var(--sent-bubble-gradient-start), var(--sent-bubble-gradient-end))
    content = content.replace('var(--bubble-sent)', 'linear-gradient(135deg, var(--sent-bubble-gradient-start), var(--sent-bubble-gradient-end))')
    
    new_root = """
/* ===========================================
   DESIGN TOKENS / CSS VARIABLES (Updated)
   =========================================== */
:root {
    /* 1. Accent (Brand Colors) */
    --primary-accent: #FF5722;
    --accent-light: #FF8A65;
    --accent-dark: #E64A19;
    --accent-glow: rgba(255, 87, 34, 0.25);
    --accent-subtle: rgba(255, 87, 34, 0.08);

    /* 2. Status & System Colors */
    --online-success: #22C55E;
    --danger-error: #EF4444;
    --msg-sent-tick-unread: rgba(100, 116, 139, 0.5);
    --msg-read-tick-blue: #38BDF8;

    /* 3. Typography (Text Colors) */
    --text-primary: #0F172A;
    --text-secondary: #475569;
    --text-muted: #94A3B8;
    --text-icon: #64748B;
    --text-on-accent: #FFFFFF;
    --typing-indicator: #FF5722;

    /* 4. Message Bubbles */
    --sent-bubble-gradient-start: #FF5722;
    --sent-bubble-gradient-end: #FF8A65;
    --received-bubble: #FFFFFF;

    /* 5. Surfaces & Backgrounds */
    --page-background: #F0F2F8;
    --chat-background: #F8FAFC;
    --panel-sidebar: rgba(255, 255, 255, 0.65);
    --header-nav: #FF5722;
    --search-bar: rgba(241, 245, 249, 0.8);
    --input-fields: #FFFFFF;
    --cards: rgba(255, 255, 255, 0.7);

    /* 6. Borders & Dividers */
    --border-line: rgba(0, 0, 0, 0.06);
    --focus-border: rgba(255, 87, 34, 0.5);
    
    /* Additional Required Variables mapping */
    --bg-overlay: rgba(255, 255, 255, 0.92);
    --bg-dropdown: #ffffff;
    --bg-modal-backdrop: rgba(248, 250, 252, 0.85);
    
    /* Shadows */
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.04);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 12px 40px rgba(0, 0, 0, 0.1);
    --shadow-dropdown: 0 8px 30px rgba(0, 0, 0, 0.12);
    --shadow-card: 0 4px 20px rgba(0, 0, 0, 0.05);

    /* Transitions */
    --transition-fast: 0.15s ease;
    --transition-normal: 0.25s ease;
    --transition-slow: 0.4s cubic-bezier(0.16, 1, 0.3, 1);

    /* Radius */
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --radius-xl: 24px;
    --radius-full: 9999px;
    
    --bubble-shadow-sent: 0 4px 20px rgba(255, 87, 34, 0.2);
    --bubble-shadow-received: 0 2px 12px rgba(0, 0, 0, 0.04);
}
"""
    
    new_dark_theme = """
/* ===========================================
   DARK THEME
   =========================================== */
body.dark-theme {
    --accent-glow: rgba(255, 87, 34, 0.30);
    --accent-subtle: rgba(255, 87, 34, 0.12);

    --msg-sent-tick-unread: rgba(255, 255, 255, 0.35);

    --text-primary: #F1F5F9;
    --text-secondary: #94A3B8;
    --text-muted: #64748B;
    --text-icon: #94A3B8;
    --typing-indicator: #FF8A65;

    --received-bubble: rgba(30, 41, 59, 1.0);

    --page-background: #020617;
    --chat-background: #0B1120;
    --panel-sidebar: rgba(15, 23, 42, 0.5);
    --header-nav: #E64A19;
    --search-bar: rgba(30, 41, 59, 0.6);
    --input-fields: rgba(30, 41, 59, 0.8);
    --cards: rgba(30, 41, 59, 0.5);

    --border-line: rgba(255, 255, 255, 0.06);
    --focus-border: rgba(255, 87, 34, 0.6);
    
    --bg-overlay: rgba(15, 23, 42, 0.95);
    --bg-dropdown: #1e293b;
    --bg-modal-backdrop: rgba(2, 6, 23, 0.85);
    
    --bubble-shadow-sent: 0 4px 20px rgba(255, 87, 34, 0.15);
    --bubble-shadow-received: 0 2px 12px rgba(0, 0, 0, 0.2);
}
"""
    
    content = content.replace('/* :root replacement marker */', new_root)
    content = content.replace('/* dark-theme replacement marker */', new_dark_theme)

    with open(filepath, 'w') as f:
        f.write(content)

replacements = {
    '--bg-page': '--page-background',
    '--bg-panel': '--panel-sidebar',
    '--bg-header': '--header-nav',
    '--bg-search': '--search-bar',
    '--bg-chat': '--chat-background',
    '--bg-card': '--cards',
    '--bg-input': '--input-fields',
    '--accent': '--primary-accent',
    '--accent-light': '--accent-light',
    '--accent-dark': '--accent-dark',
    '--accent-glow': '--accent-glow',
    '--accent-subtle': '--accent-subtle',
    '--bubble-received': '--received-bubble',
    '--text-primary': '--text-primary',
    '--text-secondary': '--text-secondary',
    '--text-muted': '--text-muted',
    '--text-icon': '--text-icon',
    '--text-on-accent': '--text-on-accent',
    '--text-typing': '--typing-indicator',
    '--text-danger': '--danger-error',
    '--online': '--online-success',
    '--tick-default': '--msg-sent-tick-unread',
    '--tick-read': '--msg-read-tick-blue',
    '--border-light': '--border-line',
    '--border-focus': '--focus-border',
    '--bg-main': '--page-background',  # login.css aliases
    '--bg-glass': '--panel-sidebar',
    '--bg-glass-hover': '--panel-sidebar',
    '--border-glass': '--border-line',
    '--border-glass-focus': '--focus-border',
    '--text-main': '--text-primary',
    '--primary': '--primary-accent',
    '--primary-hover': '--accent-light',
    '--primary-glow': '--accent-glow',
    '--error': '--danger-error',
    '--input-bg': '--input-fields',
    '--input-bg-focus': '--input-fields'
}

process_file('resources/css/chat.css', replacements)
process_file('resources/css/login.css', replacements)

print("Replacement complete.")
