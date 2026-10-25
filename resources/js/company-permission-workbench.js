(function (config) {
    return {
        state: config.state,
        groups: config.groups,
        readOnly: Boolean(config.readOnly),
        delegation: Boolean(config.delegation),
        activeKey: '',
        search: '',
        enabledOnly: false,
        init() {
            if (!this.state || Array.isArray(this.state)) this.state = {};
            const normalized = {};
            for (const [key, value] of Object.entries(this.state)) {
                if (value && typeof value === 'object' && !Array.isArray(value)) {
                    for (const [action, scope] of Object.entries(value)) normalized[key + '.' + action] = scope;
                } else normalized[key] = value;
            }
            this.state = normalized;
            if (this.delegation && !this.readOnly) {
                const keys = new Set(this.groups.flatMap(group => group.permissions.map(permission => permission.key)));
                this.state = Object.fromEntries(Object.entries(this.state).filter(([key, scope]) => keys.has(key) && scope === 'company'));
            }
            this.activeKey = this.groups.find(group => this.count(group) > 0)?.key || this.groups[0]?.key || '';
        },
        normalize(value) {
            return String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        },
        isEnabled(key) {
            const value = this.state?.[key];
            return Array.isArray(value) ? value.some(scope => scope !== 'none') : Boolean(value && value !== 'none');
        },
        count(group) { return group.permissions.filter(permission => this.isEnabled(permission.key)).length; },
        get totalEnabled() { return this.groups.reduce((total, group) => total + this.count(group), 0); },
        get filteredGroups() {
            const term = this.normalize(this.search);
            return this.groups.filter(group => {
                const matches = !term || this.normalize(group.label + ' ' + group.area + ' ' + group.description).includes(term)
                    || group.permissions.some(permission => this.normalize(permission.label + ' ' + permission.description).includes(term));
                return matches && (!this.enabledOnly || this.count(group) > 0);
            });
        },
        get activeGroup() {
            return this.filteredGroups.find(group => group.key === this.activeKey) || this.filteredGroups[0] || null;
        },
        get activePermissions() {
            if (!this.activeGroup) return [];
            const term = this.normalize(this.search);
            const groupMatches = !term || this.normalize(this.activeGroup.label + ' ' + this.activeGroup.area + ' ' + this.activeGroup.description).includes(term);
            return this.activeGroup.permissions.filter(permission =>
                (groupMatches || this.normalize(permission.label + ' ' + permission.description).includes(term))
                && (!this.enabledOnly || this.isEnabled(permission.key)));
        },
        setPermission(permission, value) {
            if (this.readOnly || !Object.hasOwn(permission.options, value)) return;
            const next = { ...this.state };
            if (value === 'none') delete next[permission.key];
            else next[permission.key] = value;
            this.state = next;
        },
        setModule(mode) {
            if (this.readOnly || !this.activeGroup) return;
            const next = { ...this.state };
            const moduleKeys = new Set(this.activeGroup.permissions.map(permission => permission.key));
            for (const key of Object.keys(next)) {
                if (moduleKeys.has(key) || key.startsWith(this.activeGroup.key + '.')) delete next[key];
            }
            for (const permission of this.activeGroup.permissions) {
                if (mode === 'all' || (mode === 'view' && permission.key.endsWith('.view'))) next[permission.key] = 'company';
                else delete next[permission.key];
            }
            this.state = next;
        },
        scopeLabel(permission) {
            const value = this.state?.[permission.key];
            const scopes = Array.isArray(value) ? value : [value || 'none'];
            if (scopes.includes('company')) return permission.options.company || 'Todos da empresa';
            if (scopes.includes('own') && scopes.includes('assigned')) return 'Próprios ou atribuídos';
            return permission.options[scopes[0]] || 'Não permitido';
        },
    };
})
