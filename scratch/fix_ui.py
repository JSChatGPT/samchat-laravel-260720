import re

def update_css():
    filepath = 'resources/css/chat.css'
    with open(filepath, 'r') as f:
        content = f.read()

    # 1. Fix sidebar header background
    content = content.replace(
        '.sidebar-header {\n    height: 60px;\n    padding: 0 16px;\n    display: flex;\n    align-items: center;\n    justify-content: space-between;\n    background: var(--header-nav);',
        '.sidebar-header {\n    height: 60px;\n    padding: 0 16px;\n    display: flex;\n    align-items: center;\n    justify-content: space-between;\n    background: var(--panel-sidebar);'
    )

    # 2. Fix empty state icon gradients and shadows
    content = content.replace(
        'background: linear-gradient(135deg, var(--primary-accent), #a78bfa);',
        'background: linear-gradient(135deg, var(--sent-bubble-gradient-start), var(--sent-bubble-gradient-end));'
    )
    content = content.replace(
        'box-shadow: 0 12px 32px rgba(99, 102, 241, 0.35);',
        'box-shadow: 0 12px 32px var(--accent-glow);'
    )
    content = content.replace(
        'background: linear-gradient(135deg, rgba(99, 102, 241, 0.3), rgba(167, 139, 250, 0.3));',
        'background: var(--accent-glow);'
    )
    
    # 3. Enhance dashboard card styles for better UI
    content = content.replace(
        '.dashboard-grid {\n    display: grid;\n    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));\n    gap: 16px;\n    width: 100%;\n    max-width: 700px;\n}',
        '.dashboard-grid {\n    display: grid;\n    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));\n    gap: 20px;\n    width: 100%;\n    max-width: 800px;\n    padding: 0 20px;\n}'
    )
    
    # Update dashboard card internal padding and add a subtle layout enhancement
    content = content.replace(
        'padding: 20px;',
        'padding: 24px 20px;'
    )

    with open(filepath, 'w') as f:
        f.write(content)

def update_blade():
    filepath = 'resources/views/chat/index.blade.php'
    with open(filepath, 'r') as f:
        content = f.read()

    # Fix UI avatars in blade
    content = content.replace('background=6366f1', 'background=FF5722')
    content = content.replace('background=b5ff94', 'background=FF5722')

    with open(filepath, 'w') as f:
        f.write(content)

def update_js():
    filepath = 'resources/js/chat.js'
    with open(filepath, 'r') as f:
        content = f.read()

    # Fix UI avatars in JS (replace background=random with background=FF5722)
    content = content.replace('background=random', 'background=FF5722&color=fff')
    
    # Re-structure the dashboard card HTML in chat.js
    old_card_html = """            card.innerHTML = `
                <div class="chat-item-pic-wrapper" style="margin-bottom: 15px;">
                    <img src="${avatarUrl}" class="chat-item-pic" style="width: 50px; height: 50px;">
                    ${isOnline}
                </div>
                <h3>${escapeHTML(name)}</h3>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 5px;">${escapeHTML(lastMsg)}</p>
                <div style="margin-top: 15px; font-size: 0.8rem; color: var(--text-muted);">
                    ${time}
                </div>
            `;"""
            
    new_card_html = """            card.innerHTML = `
                <div class="chat-item-pic-wrapper" style="margin-bottom: 16px;">
                    <img src="${avatarUrl}" class="chat-item-pic" style="width: 64px; height: 64px;">
                    ${isOnline}
                </div>
                <div class="dashboard-card-info" style="display: flex; flex-direction: column; flex: 1; justify-content: center; width: 100%;">
                    <h3 class="dashboard-card-name" style="margin: 0 0 6px 0; font-size: 1.05rem; font-weight: 600; color: var(--text-primary);">${escapeHTML(name)}</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin: 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">${escapeHTML(lastMsg)}</p>
                </div>
                <div style="margin-top: auto; padding-top: 16px; font-size: 0.75rem; color: var(--text-muted); font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">
                    ${time}
                </div>
            `;"""

    content = content.replace(old_card_html, new_card_html)
    
    # Make sure we didn't miss anything
    if old_card_html not in content and new_card_html not in content:
        print("Warning: old_card_html not found in chat.js!")

    with open(filepath, 'w') as f:
        f.write(content)

update_css()
update_blade()
update_js()
print("UI polish fixes applied successfully.")
