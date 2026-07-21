import re
import glob
import os

def process_file(filepath, replacements):
    with open(filepath, 'r') as f:
        content = f.read()
    
    # Replace variable usages
    for old_var, new_var in replacements.items():
        content = re.sub(r'var\(' + re.escape(old_var) + r'\)', f'var({new_var})', content)
        # Handle cases with fallbacks, e.g. var(--accent-red, #ef4444)
        content = re.sub(r'var\(' + re.escape(old_var) + r'\s*,([^)]+)\)', f'var({new_var}, \\1)', content)

    # Specific replacements
    content = content.replace('var(--bubble-sent)', 'linear-gradient(135deg, var(--sent-bubble-gradient-start), var(--sent-bubble-gradient-end))')
    
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
    '--accent-primary': '--primary-accent',
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
    '--accent-red': '--danger-error',
    '--online': '--online-success',
    '--tick-default': '--msg-sent-tick-unread',
    '--tick-read': '--msg-read-tick-blue',
    '--border-light': '--border-line',
    '--border-focus': '--focus-border',
    '--bg-main': '--page-background',
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

process_file('resources/js/chat.js', replacements)
process_file('resources/views/chat/index.blade.php', replacements)

print("Replacement in JS and Blade complete.")
